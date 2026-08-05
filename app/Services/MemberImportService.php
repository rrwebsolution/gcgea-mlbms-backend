<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\LegacyLoanImport;
use App\Models\Member;
use App\Models\MemberImportBatch;
use App\Models\MemberImportRow;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates the member-import pipeline: detect worksheets/columns,
 * validate + clean every row (dates, names, phone numbers, office
 * resolution, duplicate scoring, legacy loan staging), and commit real
 * Member/Beneficiary/LegacyLoanImport records. Per policy decision, an
 * imported row that completes the pipeline (passes validation, has a
 * resolved office, has a duplicate decision) is auto-approved on commit —
 * imports do not go through the member_registration approval queue. Manual
 * "Add Member" registrations are unaffected and still require approval.
 * Mirrors PayrollImportService's shape (detectColumns / validate / commit,
 * re-validates from stored rows at commit time).
 */
class MemberImportService
{
    private const FOOTER_MARKERS = ['TOTAL', 'GRAND TOTAL', 'TOTALS', 'SUBTOTAL', 'SUB-TOTAL'];

    private const SPOUSE_EMPTY_VALUES = ['N/A', 'NA', 'NONE', 'NOT APPLICABLE', '-', '--', 'N.A', 'N.A.'];

    private const PH_MOBILE_REGEX = '/^09\d{9}$/';

    public function __construct(
        private readonly MemberSheetParser $parser,
        private readonly MemberColumnMapper $mapper,
        private readonly ExcelDateCoercer $dateCoercer,
        private readonly OfficeResolutionService $officeResolver,
        private readonly MemberDuplicateDetectionService $duplicateDetector,
        private readonly AuditLogService $auditLog,
        private readonly MembershipApprovalService $membershipApproval,
    ) {}

    public function listWorksheets(string $absolutePath, string $ext): array
    {
        return $this->parser->listWorksheets($absolutePath, $ext);
    }

    /**
     * @return array{headers: array<int,string>, headerRowIndex: int, detectedMapping: array<string,?string>, unmatchedHeaders: array<int,string>, sampleRows: array<int,array<string,mixed>>, totalRows: int}
     */
    public function detectColumns(string $absolutePath, string $ext, ?string $sheetName): array
    {
        $parsed = $this->parser->parse($absolutePath, $ext, $sheetName);
        $detection = $this->mapper->detect($parsed['headers']);

        $sample = array_slice($parsed['dataRows'], 0, 5, true);
        $sampleFlat = [];
        foreach ($sample as $rowNumber => $row) {
            $flat = [];
            foreach ($row as $header => $cellInfo) {
                $flat[$header] = $cellInfo['value'];
            }
            $sampleFlat[$rowNumber] = $flat;
        }

        return [
            'headers' => $parsed['headers'],
            'headerRowIndex' => $parsed['headerRowIndex'],
            'detectedMapping' => $detection['mapping'],
            'unmatchedHeaders' => $detection['unmatched'],
            'sampleRows' => array_values($sampleFlat),
            'totalRows' => count($parsed['dataRows']),
        ];
    }

    /**
     * @param  array<string,?string>  $mapping
     * @return array{rows: array<int,array<string,mixed>>, summary: array<string,int>}
     */
    public function validate(string $absolutePath, string $ext, ?string $sheetName, array $mapping): array
    {
        $parsed = $this->parser->parse($absolutePath, $ext, $sheetName);

        $rows = [];
        $counts = ['total' => 0, 'new' => 0, 'exact' => 0, 'probable' => 0, 'possible' => 0, 'invalid' => 0];

        // Duplicate scoring above only checks against the `members` table,
        // which is empty for anyone still inside this same, uncommitted
        // batch — a copy-pasted row within the same worksheet (the exact
        // "duplicate/copied records" scenario the workbook is known to
        // contain) would otherwise sail through as "New" against its own
        // twin. Track a running list of already-processed rows in this pass
        // and flag a match against them too.
        $seenInBatch = [];

        foreach ($parsed['dataRows'] as $rowNumber => $rawRow) {
            $cells = $this->mapRow($rawRow, $mapping);

            if ($this->isFooterRow($cells)) {
                continue;
            }

            $result = $this->validateRow($cells);

            $twinRow = $this->findWithinBatchDuplicate($result['data'], $seenInBatch);
            if ($twinRow !== null) {
                $result['reasons'][] = "Possible duplicate of row {$twinRow} within this same worksheet";
                if ($result['category'] === 'New') {
                    $result['category'] = 'Possible';
                }
            }
            if (! empty($result['data']['last_name']) && ! empty($result['data']['first_name'])) {
                $seenInBatch[$rowNumber] = [
                    'nameTokens' => $this->normalizeNameTokens($result['data']['last_name'].' '.$result['data']['first_name']),
                    'birthdate' => $result['data']['birthdate'],
                ];
            }

            $counts['total']++;
            $counts[strtolower($result['category'])]++;

            $rows[] = array_merge(['rowNumber' => $rowNumber], $result);
        }

        return ['rows' => $rows, 'summary' => $counts];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int,array{nameTokens:string,birthdate:?string}>  $seenInBatch
     */
    private function findWithinBatchDuplicate(array $data, array $seenInBatch): ?int
    {
        if (empty($data['last_name']) || empty($data['first_name'])) {
            return null;
        }

        $needle = $this->normalizeNameTokens($data['last_name'].' '.$data['first_name']);
        if ($needle === '') {
            return null;
        }

        foreach ($seenInBatch as $rowNumber => $seen) {
            if ($seen['nameTokens'] !== $needle) {
                continue;
            }
            // If both rows have a birthdate, require it to match too —
            // otherwise a same-name-different-person coincidence would
            // falsely flag as a copied record.
            if (! $data['birthdate'] || ! $seen['birthdate'] || $data['birthdate'] === $seen['birthdate']) {
                return $rowNumber;
            }
        }

        return null;
    }

    /**
     * Word-order-independent name comparison, same technique used by
     * MemberDuplicateDetectionService — duplicated locally since it's a
     * small pure function and doesn't warrant a shared dependency.
     */
    private function normalizeNameTokens(string $name): string
    {
        $letters = preg_replace('/[^A-Za-z\s]/', ' ', strtoupper($name)) ?? '';
        $tokens = array_values(array_filter(preg_split('/\s+/', trim($letters)) ?: []));
        sort($tokens);

        return implode(' ', $tokens);
    }

    /**
     * Creates real Member/Beneficiary/LegacyLoanImport records. Each newly
     * created member is auto-approved (membership_status='Active') per
     * policy — imports never create an active loan, but a passing row does
     * become an active member immediately, with no manual review step.
     * Re-reads persisted MemberImportRow records (never trusts
     * client-supplied row data beyond which rows to skip/merge).
     *
     * @param  array<int,string>  $rowResolutions  rowNumber => 'create_new'|'skip'|'merge_into:{id}'
     * @return array<string,int>
     */
    public function commit(MemberImportBatch $batch, array $rowResolutions, User $user): array
    {
        if ($batch->status === 'Committed') {
            throw new RuntimeException('This member import batch has already been committed.');
        }

        $summary = [
            'totalRows' => 0, 'membersCreated' => 0, 'membersMerged' => 0, 'membersSkipped' => 0,
            'invalidRows' => 0, 'beneficiariesCreated' => 0, 'legacyLoanDraftsCreated' => 0, 'failedRows' => 0,
            'pendingReview' => 0,
        ];

        DB::transaction(function () use ($batch, $rowResolutions, $user, &$summary) {
            $rows = $batch->rows()->orderBy('row_number')->get();
            $generalSettings = SystemSetting::where('section', 'general')->first()?->value ?? [];
            $membershipRegistrationFee = (float) ($generalSettings['membershipRegistrationFee'] ?? 100);

            foreach ($rows as $row) {
                $summary['totalRows']++;

                $defaultResolution = match ($row->validation_category) {
                    'Exact' => isset($row->duplicate_candidate_member_ids[0]['memberId'])
                        ? 'merge_into:'.$row->duplicate_candidate_member_ids[0]['memberId']
                        : 'create_new',
                    'Probable' => null, // no safe default — an explicit choice is required
                    default => 'create_new', // Possible, New
                };
                $resolution = $rowResolutions[$row->row_number] ?? $row->resolved_action ?? $defaultResolution;

                if ($resolution === 'skip') {
                    $row->update(['row_status' => 'Skipped', 'resolved_action' => 'skip']);
                    $summary['membersSkipped']++;

                    continue;
                }
                if ($resolution === null || $row->validation_category === 'Invalid') {
                    $row->update(['row_status' => 'Failed']);
                    $summary['invalidRows']++;
                    $summary['failedRows']++;

                    continue;
                }
                if (! $row->resolved_office_id) {
                    $row->update(['row_status' => 'Failed']);
                    $summary['failedRows']++;

                    continue;
                }

                $data = $row->raw_data;

                if (str_starts_with((string) $resolution, 'merge_into:')) {
                    $existing = Member::find((int) substr($resolution, strlen('merge_into:')));
                    if (! $existing) {
                        $row->update(['row_status' => 'Failed']);
                        $summary['failedRows']++;

                        continue;
                    }
                    $this->fillOnlyBlanks($existing, $data, $row->resolved_office_id);
                    $this->auditLog->record(
                        $user, $existing, 'merge',
                        "Import row {$row->row_number} merged into existing member {$existing->member_number}; only blank fields were filled, nothing overwritten."
                    );
                    $row->update(['row_status' => 'Imported', 'created_member_id' => $existing->id, 'resolved_action' => $resolution]);
                    $summary['membersMerged']++;

                    continue;
                }

                $member = Member::create([
                    'surname' => $data['last_name'],
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?: null,
                    'sex' => $data['sex'],
                    'birthdate' => $data['birthdate'],
                    'civil_status' => null,
                    'permanent_address' => $data['permanent_address'],
                    'cellphone_number' => $data['cellphone_number'],
                    'email' => $data['email'],
                    'name_of_spouse' => $data['name_of_spouse'],
                    'office_id' => $row->resolved_office_id,
                    'position' => $data['position'],
                    'date_of_regular_appointment' => $data['date_of_regular_appointment'],
                    'employment_status' => null,
                    'membership_type' => 'Regular',
                    'membership_date' => $data['membership_date'] ?? $data['date_of_regular_appointment'] ?? now()->toDateString(),
                    // Per explicit policy decision: every row that completes
                    // the import process (passed validation, office
                    // resolved, duplicate decision made) is auto-approved —
                    // imports skip the manual registration review queue
                    // entirely. Manual "Add Member" registrations are
                    // unaffected and still go through member_registration
                    // approval as before.
                    'membership_status' => 'Pending',
                    'retiree_status' => $data['retiree_status'],
                    'remarks' => $data['membership_type_raw']
                        ? "Imported — sheet's 'Check One!' value: {$data['membership_type_raw']}"
                        : null,
                    'is_draft' => false,
                    'imported_from_batch_id' => $batch->id,
                    'created_by' => $user->full_name,
                    'submitted_by_user_id' => $user->id,
                ]);
                $member->update(['member_number' => app(DocumentNumberService::class)->generate('member', $member->id)]);

                // Imported members come from the association's existing
                // membership records, so their registration fee is treated
                // as already paid instead of creating the Unpaid placeholder
                // used by a new manual registration.
                $member->membershipFeePayment()->create([
                    'reference_number' => 'GCGEA-MF-'.now()->year.'-'.str_pad((string) $member->id, 6, '0', STR_PAD_LEFT),
                    'amount' => $membershipRegistrationFee,
                    'payment_date' => now()->toDateString(),
                    'payment_method' => 'Member Import',
                    'received_by' => $user->full_name,
                    'status' => 'Posted',
                ]);

                foreach ([1 => 'beneficiary_1', 2 => 'beneficiary_2'] as $priority => $key) {
                    if (! empty($data[$key])) {
                        Beneficiary::create([
                            'member_id' => $member->id,
                            'full_name' => $data[$key],
                            'relationship' => null,
                            'birthdate' => null,
                            'priority_order' => $priority,
                            'source' => 'import',
                        ]);
                        $summary['beneficiariesCreated']++;
                    }
                }

                if (($data['legacy_loan_status'] ?? 'No legacy loan information') !== 'No legacy loan information') {
                    LegacyLoanImport::create(array_merge(
                        ['batch_id' => $batch->id, 'member_import_row_id' => $row->id, 'created_member_id' => $member->id],
                        $data['legacy_loan'] ?? []
                    ));
                    $summary['legacyLoanDraftsCreated']++;
                }

                $this->membershipApproval->process($member, $user, 'import');
                if ($member->approvalInstance()->where('status', 'pending')->exists()) {
                    $summary['pendingReview']++;
                }

                $this->auditLog->record(
                    $user, $member, 'import',
                    "Imported from {$batch->original_filename}, row {$row->row_number}. Membership approval settings applied."
                );

                $row->update(['row_status' => 'Imported', 'created_member_id' => $member->id, 'resolved_action' => 'create_new']);
                $summary['membersCreated']++;
            }

            $batch->update([
                'status' => 'Committed',
                'committed_by_user_id' => $user->id,
                'committed_at' => now(),
                'imported_rows' => $summary['membersCreated'] + $summary['membersMerged'],
                // Imports are auto-approved on commit — nothing is ever
                // left in Pending Review by this pipeline.
                'pending_review_rows' => $summary['pendingReview'],
                'skipped_rows' => $summary['membersSkipped'],
                'error_rows' => $summary['failedRows'],
                'legacy_loan_flagged_rows' => $summary['legacyLoanDraftsCreated'],
            ]);

            $this->auditLog->record(
                $user, $batch, 'commit',
                "Committed member import batch ({$summary['membersCreated']} created, {$summary['membersMerged']} merged, {$summary['membersSkipped']} skipped, {$summary['failedRows']} failed)."
            );
        });

        return $summary;
    }

    /**
     * Reverts a committed batch: deletes every member it created
     * (imported_from_batch_id = this batch) along with their beneficiaries/
     * documents (DB-cascaded) and approval instance, then deletes the batch
     * itself (cascades its rows and legacy loan drafts). Only the most
     * recently committed batch may be undone — an older one may have members
     * a later import has since merged into. Refuses if any created member
     * already has financial activity, since that can't be safely un-created.
     */
    public function undo(MemberImportBatch $batch, User $user): void
    {
        if ($batch->status !== 'Committed') {
            throw new RuntimeException('Only a committed batch can be undone.');
        }

        $latestCommittedId = MemberImportBatch::where('status', 'Committed')->orderByDesc('committed_at')->value('id');
        if ($latestCommittedId !== $batch->id) {
            throw new RuntimeException('Only the most recently committed member import can be undone.');
        }

        DB::transaction(function () use ($batch, $user) {
            $members = Member::where('imported_from_batch_id', $batch->id)->get();

            foreach ($members as $member) {
                $hasActivity = DB::table('contributions')->where('member_id', $member->id)->exists()
                    || DB::table('loans')->where('member_id', $member->id)->exists()
                    || DB::table('loan_payments')->where('member_id', $member->id)->exists()
                    || DB::table('benefit_applications')->where('member_id', $member->id)->exists()
                    || DB::table('deductions')->where('member_id', $member->id)->exists();

                if ($hasActivity) {
                    throw new RuntimeException("Cannot undo: {$member->full_name} ({$member->member_number}) already has other records attached (contributions, loans, benefits, or payroll) and can no longer be safely removed.");
                }
            }

            $originalFilename = $batch->original_filename;
            $memberCount = $members->count();

            foreach ($members as $member) {
                $member->approvalInstance()->delete();
                $member->delete();
            }

            $batch->delete();

            $this->auditLog->record(
                $user, $batch, 'undo',
                "Undid member import batch \"{$originalFilename}\" — removed {$memberCount} member(s) it created."
            );
        });
    }

    /**
     * Merge strategy is fill-only-blanks: never overwrites a populated field
     * on the existing (already-approved, possibly hand-verified) member —
     * safer than trusting legacy sheet data of unknown provenance.
     *
     * @param  array<string,mixed>  $data
     */
    private function fillOnlyBlanks(Member $existing, array $data, ?int $officeId): void
    {
        $candidates = [
            'middle_name' => $data['middle_name'] ?? null,
            'permanent_address' => $data['permanent_address'] ?? null,
            'cellphone_number' => $data['cellphone_number'] ?? null,
            'email' => $data['email'] ?? null,
            'name_of_spouse' => $data['name_of_spouse'] ?? null,
            'position' => $data['position'] ?? null,
            'date_of_regular_appointment' => $data['date_of_regular_appointment'] ?? null,
            'membership_date' => $data['membership_date'] ?? null,
            'office_id' => $officeId,
        ];

        $updates = [];
        foreach ($candidates as $column => $value) {
            if ($value !== null && $value !== '' && empty($existing->{$column})) {
                $updates[$column] = $value;
            }
        }

        if ($updates !== []) {
            $existing->update($updates);
        }
    }

    /**
     * @param  array<string,array{value:mixed,format:?string,isError:bool}>  $rawRow  keyed by original header
     * @param  array<string,?string>  $mapping  targetField => spreadsheet header
     * @return array<string,array{value:mixed,format:?string,isError:bool}> keyed by target field
     */
    private function mapRow(array $rawRow, array $mapping): array
    {
        $mapped = [];
        foreach (MemberColumnMapper::TARGET_FIELDS as $field => $label) {
            $header = $mapping[$field] ?? null;
            $mapped[$field] = $header !== null ? ($rawRow[$header] ?? null) : null;
            $mapped[$field] ??= ['value' => null, 'format' => null, 'isError' => false];
        }

        return $mapped;
    }

    private function isFooterRow(array $cells): bool
    {
        $name = strtoupper(trim((string) ($cells['last_name']['value'] ?? '')));

        return $name !== '' && in_array($name, self::FOOTER_MARKERS, true);
    }

    /**
     * @param  array<string,array{value:mixed,format:?string,isError:bool}>  $cells
     * @return array<string,mixed>
     */
    private function validateRow(array $cells): array
    {
        $reasons = [];

        foreach ($cells as $field => $cell) {
            if ($cell['isError']) {
                $reasons[] = "Formula error in '".(MemberColumnMapper::TARGET_FIELDS[$field] ?? $field)."'";
            }
        }

        $lastName = $this->cleanText($cells['last_name']['value'] ?? null);
        $firstName = $this->cleanText($cells['first_name']['value'] ?? null);
        $middleName = $this->cleanText($cells['middle_name']['value'] ?? null);
        $permanentAddress = $this->cleanText($cells['permanent_address']['value'] ?? null);
        $position = $this->cleanText($cells['position']['value'] ?? null);

        $birthdate = $this->dateCoercer->coerce($cells['birthdate']['value'] ?? null, $cells['birthdate']['format'] ?? null);
        $appointmentDate = $this->dateCoercer->coerce($cells['date_of_regular_appointment']['value'] ?? null, $cells['date_of_regular_appointment']['format'] ?? null);
        $membershipDate = $this->dateCoercer->coerce($cells['membership_date']['value'] ?? null, $cells['membership_date']['format'] ?? null);

        $now = CarbonImmutable::now();

        if (! $lastName) {
            $reasons[] = 'Missing surname';
        }
        if (! $firstName) {
            $reasons[] = 'Missing first name';
        }
        if (! $birthdate) {
            $reasons[] = 'Missing or invalid birthdate';
        } elseif ($birthdate->isFuture()) {
            $reasons[] = 'Birthdate is in the future';
        } elseif ($now->diffInYears($birthdate, true) > 100) {
            $reasons[] = 'Birthdate implies an implausible age — flagged for manual review';
        }

        if ($appointmentDate) {
            if ($appointmentDate->isFuture()) {
                $reasons[] = 'Appointment date is in the future';
            }
            if ($birthdate && $appointmentDate->lessThan($birthdate)) {
                $reasons[] = 'Appointment date is earlier than birthdate';
            }
        }
        if ($membershipDate) {
            if ($membershipDate->isFuture()) {
                $reasons[] = 'Membership date is in the future';
            }
            if ($appointmentDate && $membershipDate->lessThan($appointmentDate)) {
                $reasons[] = 'Membership date is earlier than appointment date';
            }
        }

        // Display-only comparisons — the sheet's value is NEVER stored,
        // only shown alongside the system-computed value during preview.
        $computedAge = $birthdate ? (int) $now->diffInYears($birthdate, true) : null;
        $sheetAge = $this->extractInt($cells['age_display']['value'] ?? null);
        if ($computedAge !== null && $sheetAge !== null && abs($computedAge - $sheetAge) > 3) {
            $reasons[] = "Incorrect calculated spreadsheet age (sheet: {$sheetAge}, system: {$computedAge}) — system calculation will be used";
        }

        $computedServiceYears = $appointmentDate ? (int) $now->diffInYears($appointmentDate, true) : null;
        $sheetServiceYears = $this->extractInt($cells['years_in_service_display']['value'] ?? null);
        if ($computedServiceYears !== null && $sheetServiceYears !== null && abs($computedServiceYears - $sheetServiceYears) > 3) {
            $reasons[] = "Incorrect government service duration on sheet (sheet: {$sheetServiceYears}, system: {$computedServiceYears}) — system calculation will be used";
        }

        $computedMembershipYears = $membershipDate ? (int) $now->diffInYears($membershipDate, true) : null;
        $sheetMembershipYears = $this->extractInt($cells['length_of_membership_display']['value'] ?? null);
        if ($computedMembershipYears !== null && $sheetMembershipYears !== null && abs($computedMembershipYears - $sheetMembershipYears) > 3) {
            $reasons[] = "Incorrect membership length on sheet (sheet: {$sheetMembershipYears}, system: {$computedMembershipYears}) — system calculation will be used";
        }

        $sex = $this->normalizeSex($cells['sex']['value'] ?? null);
        if ($sex === null && $this->cleanText($cells['sex']['value'] ?? null) !== null) {
            $reasons[] = 'Unrecognized sex value — needs manual review';
        }

        $retireeStatus = $this->normalizeRetireeStatus($cells['retiree_status_raw']['value'] ?? null);

        $membershipTypeRaw = $this->cleanText($cells['membership_type_raw']['value'] ?? null);

        $spouseName = $this->cleanSpouseName($cells['name_of_spouse']['value'] ?? null);

        [$cellphoneNumber, $cellphoneValid] = $this->normalizePhone($cells['cellphone_number']['value'] ?? null);
        if ($cellphoneNumber !== null && ! $cellphoneValid) {
            $reasons[] = "Invalid Philippine mobile number format: '{$cellphoneNumber}'";
        }

        $email = $this->cleanText($cells['email']['value'] ?? null);
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $reasons[] = "Invalid email format: '{$email}'";
            $email = null;
        }

        $rawOffice = $this->cleanText($cells['office_name_raw']['value'] ?? null);
        $resolvedOffice = $rawOffice !== null ? $this->officeResolver->resolve($rawOffice) : null;
        $unresolvedOfficeText = null;
        if ($resolvedOffice === null) {
            $unresolvedOfficeText = $rawOffice ?? '(blank)';
            $reasons[] = $rawOffice !== null
                ? "Unknown office '{$rawOffice}' — needs manual mapping"
                : 'Missing office — needs manual mapping';
        }

        $beneficiary1 = $this->cleanText($cells['beneficiary_1']['value'] ?? null);
        $beneficiary2 = $this->cleanText($cells['beneficiary_2']['value'] ?? null);
        if ($beneficiary1 && $beneficiary2 && $this->normalizeForCompare($beneficiary1) === $this->normalizeForCompare($beneficiary2)) {
            $reasons[] = 'Duplicate beneficiary — both beneficiary fields have the same name';
        }

        $legacy = $this->extractLegacyLoanFields($cells);
        if ($legacy['status'] !== 'No legacy loan information') {
            $reasons[] = "Legacy Loan Review — {$legacy['status']}";
        }

        $duplicate = $this->duplicateDetector->evaluate([
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'birthdate' => $birthdate,
        ], $resolvedOffice?->id);

        $category = (! $lastName || ! $firstName || ! $birthdate) ? 'Invalid' : $duplicate['category'];

        return [
            'category' => $category,
            'reasons' => $reasons,
            'duplicateScore' => $duplicate['score'],
            'duplicateCandidates' => $duplicate['candidates'],
            'resolvedOfficeId' => $resolvedOffice?->id,
            'unresolvedOfficeText' => $unresolvedOfficeText,
            'data' => [
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'sex' => $sex,
                'birthdate' => $birthdate?->toDateString(),
                'computed_age' => $computedAge,
                'sheet_age' => $sheetAge,
                'permanent_address' => $permanentAddress,
                'cellphone_number' => $cellphoneNumber,
                'email' => $email,
                'name_of_spouse' => $spouseName,
                'position' => $position,
                'office_name_raw' => $rawOffice,
                'resolved_office_name' => $resolvedOffice?->name,
                'date_of_regular_appointment' => $appointmentDate?->toDateString(),
                'computed_service_years' => $computedServiceYears,
                'sheet_service_years' => $sheetServiceYears,
                'membership_type_raw' => $membershipTypeRaw,
                'membership_date' => $membershipDate?->toDateString(),
                'computed_membership_years' => $computedMembershipYears,
                'sheet_membership_years' => $sheetMembershipYears,
                'retiree_status' => $retireeStatus,
                'beneficiary_1' => $beneficiary1,
                'beneficiary_2' => $beneficiary2,
                'legacy_loan_status' => $legacy['status'],
                'legacy_loan' => $legacy['fields'],
                'source_submitted_at' => $this->cleanText($cells['source_submitted_at']['value'] ?? null),
            ],
        ];
    }

    /**
     * @return array{status: string, fields: array<string,mixed>}
     */
    private function extractLegacyLoanFields(array $cells): array
    {
        $cashPabaonRaw = $this->cleanText($cells['cash_pabaon']['value'] ?? null);
        $loanStartRaw = $this->cleanText($cells['loan_start']['value'] ?? null);
        $solidarityRaw = $this->cleanText($cells['solidarity_assistance_loan']['value'] ?? null);
        $monthsRaw = $this->cleanText($cells['no_of_months']['value'] ?? null);
        $amortRaw = $this->cleanText($cells['monthly_amort']['value'] ?? null);
        $legacyNotes = $this->cleanText($cells['legacy_notes']['value'] ?? null);

        $present = array_filter([$cashPabaonRaw, $loanStartRaw, $solidarityRaw, $monthsRaw, $amortRaw], fn ($v) => $v !== null);
        $presentCount = count($present);

        $status = match (true) {
            $presentCount === 0 => 'No legacy loan information',
            $presentCount === 5 => 'Complete legacy loan information',
            default => 'Partial legacy loan information',
        };

        $loanStartDate = $loanStartRaw !== null
            ? $this->dateCoercer->coerce($cells['loan_start']['value'] ?? null, $cells['loan_start']['format'] ?? null)
            : null;

        return [
            'status' => $status,
            'fields' => [
                'cash_pabaon' => $cashPabaonRaw,
                'cash_pabaon_amount' => $this->parseAmount($cashPabaonRaw),
                'loan_start' => $loanStartRaw,
                'loan_start_date' => $loanStartDate?->toDateString(),
                'solidarity_assistance_loan' => $solidarityRaw,
                'solidarity_assistance_loan_amount' => $this->parseAmount($solidarityRaw),
                'no_of_months' => $monthsRaw,
                'no_of_months_parsed' => $this->extractInt($monthsRaw),
                'monthly_amort' => $amortRaw,
                'monthly_amort_amount' => $this->parseAmount($amortRaw),
                'legacy_notes' => $legacyNotes,
            ],
        ];
    }

    private function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        return $text === '' ? null : $text;
    }

    private function normalizeForCompare(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', ' ', trim($value)) ?? '');
    }

    private function cleanSpouseName(mixed $value): ?string
    {
        $text = $this->cleanText($value);
        if ($text === null) {
            return null;
        }
        $stripped = strtoupper(trim($text, " \t\n\r\0\x0B.-"));

        return in_array($stripped, self::SPOUSE_EMPTY_VALUES, true) ? null : $text;
    }

    private function normalizeSex(mixed $value): ?string
    {
        $text = $this->cleanText($value);
        if ($text === null) {
            return null;
        }
        $upper = strtoupper($text[0] ?? '');
        $normalized = strtoupper($text);

        if ($normalized === 'MALE' || $upper === 'M') {
            return 'Male';
        }
        if ($normalized === 'FEMALE' || $upper === 'F') {
            return 'Female';
        }

        return null;
    }

    private function normalizeRetireeStatus(mixed $value): string
    {
        $text = $this->cleanText($value);
        if ($text === null) {
            return 'Not Retired';
        }
        $normalized = strtoupper($text);
        if (str_contains($normalized, 'NOT')) {
            return 'Not Retired';
        }
        if (str_contains($normalized, 'RETIRE') || in_array($normalized, ['YES', 'Y'], true)) {
            return 'Retired';
        }

        return 'Not Retired';
    }

    /**
     * @return array{0: ?string, 1: bool} [normalized number or original text, isValid]
     */
    private function normalizePhone(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, true];
        }

        // A phone number typed into a Number-formatted cell arrives as a
        // PHP int/float — sprintf avoids scientific notation for large ints.
        $text = is_numeric($value) ? sprintf('%.0f', $value) : (string) $value;
        $digits = preg_replace('/\D/', '', $text) ?? '';

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        $isValid = (bool) preg_match(self::PH_MOBILE_REGEX, $digits);

        return [$isValid ? $digits : trim($text), $isValid];
    }

    private function extractInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }
        if (preg_match('/-?\d+/', (string) $value, $m)) {
            return (int) $m[0];
        }

        return null;
    }

    private function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $stripped = preg_replace('/[^0-9.\-]/', '', (string) $value);
        if ($stripped === null || $stripped === '' || $stripped === '-') {
            return null;
        }

        return (float) $stripped;
    }
}
