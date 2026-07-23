<?php

namespace App\Services;

use App\Exceptions\LoanPaymentPostingException;
use App\Exceptions\PayrollImportRollbackException;
use App\Models\Contribution;
use App\Models\Deduction;
use App\Models\DeductionType;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Member;
use App\Models\PayrollImportBatch;
use App\Models\PayrollImportRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the payroll import pipeline: detect columns, validate rows
 * against members/loans, and (in a later phase) commit/rollback the actual
 * Loan Payment / Contribution / Deduction records.
 */
class PayrollImportService
{
    private const TOLERANCE = 0.01;

    /** Loan statuses a payment can legitimately be posted against — mirrors LoanPaymentController::store. */
    private const POSTABLE_LOAN_STATUSES = ['Released', 'Active', 'Overdue', 'Restructured'];

    private const FOOTER_MARKERS = ['TOTAL', 'GRAND TOTAL', 'TOTALS', 'SUBTOTAL', 'SUB-TOTAL', 'SUB TOTAL'];

    public function __construct(
        private readonly PayrollSheetParser $parser,
        private readonly PayrollColumnMapper $mapper,
        private readonly LoanPaymentPoster $poster,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @return array{headers: array<int,string>, detectedMapping: array<string,?string>, unmatchedHeaders: array<int,string>, sampleRows: array<int,array<string,mixed>>, totalRows: int, sheetNames: array<int,string>, selectedSheet: string}
     */
    public function detectColumns(string $absolutePath, ?string $sheetName = null): array
    {
        $parsed = $this->parser->parse($absolutePath, $sheetName);
        $detection = $this->mapper->detect($parsed['headers']);

        $sample = array_slice($parsed['dataRows'], 0, 5, true);

        return [
            'headers' => $parsed['headers'],
            'headerRowIndex' => $parsed['headerRowIndex'],
            'detectedMapping' => $detection['mapping'],
            'unmatchedHeaders' => $detection['unmatched'],
            'sampleRows' => array_values($sample),
            'totalRows' => count($parsed['dataRows']),
            'sheetNames' => $parsed['sheetNames'],
            'selectedSheet' => $parsed['selectedSheet'],
        ];
    }

    /**
     * Re-parses the stored workbook with the given mapping and validates every
     * row. Used by both the preview endpoint (display only) and the commit
     * endpoint (which re-validates from the stored file rather than trusting
     * client-supplied row data).
     *
     * @param  array<string,?string>  $mapping
     * @return array{rows: array<int,array<string,mixed>>, summary: array<string,int>, batchWarnings: array<int,string>}
     */
    public function validate(string $absolutePath, array $mapping, PayrollImportBatch $batch, ?string $sheetName = null): array
    {
        $parsed = $this->parser->parse($absolutePath, $sheetName);

        $batchWarnings = [];
        $duplicateBatch = PayrollImportBatch::query()
            ->where('payroll_period', $batch->payroll_period)
            ->where('status', 'Committed')
            ->where('id', '!=', $batch->id)
            ->first();
        if ($duplicateBatch) {
            $batchWarnings[] = "A payroll import for {$batch->payroll_period} was already committed on ".
                $duplicateBatch->committed_at?->toDateString().' by '.($duplicateBatch->committedBy?->full_name ?? 'someone').'.';
        }

        $rows = [];
        $counts = ['total' => 0, 'valid' => 0, 'warning' => 0, 'invalid' => 0];

        foreach ($parsed['dataRows'] as $rowNumber => $rawRow) {
            $mapped = $this->mapRow($rawRow, $mapping);

            if ($this->isFooterRow($mapped)) {
                continue;
            }

            $validated = $this->validateRow($mapped);

            $counts['total']++;
            $counts[strtolower($validated['category'])]++;

            $rows[] = array_merge(['rowNumber' => $rowNumber, 'data' => $mapped], $validated);
        }

        return [
            'rows' => $rows,
            'summary' => $counts,
            'batchWarnings' => $batchWarnings,
        ];
    }

    /**
     * Creates the actual Loan Payment / Contribution / Deduction records for
     * a previously-previewed batch. Re-reads from the stored PayrollImportRow
     * records (never client-supplied amounts) — only which rows to exclude
     * and how ambiguous-name conflicts were resolved come from the caller.
     *
     * @param  array<int,string>  $resolvedMatches  rowNumber => memberId, for rows the conflict dialog resolved
     * @param  array<int,int>  $excludedRowNumbers
     * @return array<string,int|float>
     */
    public function commit(PayrollImportBatch $batch, array $resolvedMatches, array $excludedRowNumbers, bool $includeWarnings, User $user): array
    {
        if ($batch->status === 'Committed') {
            throw new \RuntimeException('This payroll import batch has already been committed.');
        }

        $summary = [
            'totalRows' => 0, 'importedMembers' => 0, 'skippedRows' => 0,
            'warnings' => 0, 'errors' => 0,
            'loanPaymentsImported' => 0, 'contributionsCreated' => 0, 'deductionsCreated' => 0,
            'totalPayrollDeduction' => 0.0,
        ];
        $loanPaymentsAmount = 0.0;
        $contributionsAmount = 0.0;
        $deductionsAmount = 0.0;
        $canPostLoanPayments = $user->hasPermission('loan_payments.import');
        $paymentDate = now()->toDateString();
        $payrollReference = $batch->payroll_reference ?: $batch->token;
        $defaultDeductionType = DeductionType::query()->where('code', 'pabaon')->first()
            ?? DeductionType::query()->where('is_active', true)->orderBy('sort_order')->first();

        try {
            $this->runCommitTransaction(
                $batch, $resolvedMatches, $excludedRowNumbers, $includeWarnings, $user,
                $summary, $loanPaymentsAmount, $contributionsAmount, $deductionsAmount,
                $canPostLoanPayments, $paymentDate, $payrollReference, $defaultDeductionType
            );
        } catch (\Throwable $e) {
            $this->auditLog->record($user, $batch, 'failed', "Payroll import failed for {$batch->payroll_period}: {$e->getMessage()}", 'Failed');

            throw $e;
        }

        return $summary;
    }

    /**
     * @param  array<int,string>  $resolvedMatches
     * @param  array<int,int>  $excludedRowNumbers
     * @param  array<string,int|float>  $summary
     */
    private function runCommitTransaction(
        PayrollImportBatch $batch,
        array $resolvedMatches,
        array $excludedRowNumbers,
        bool $includeWarnings,
        User $user,
        array &$summary,
        float &$loanPaymentsAmount,
        float &$contributionsAmount,
        float &$deductionsAmount,
        bool $canPostLoanPayments,
        string $paymentDate,
        string $payrollReference,
        ?DeductionType $defaultDeductionType,
    ): void {
        DB::transaction(function () use (
            $batch, $resolvedMatches, $excludedRowNumbers, $includeWarnings, $user,
            &$summary, &$loanPaymentsAmount, &$contributionsAmount, &$deductionsAmount,
            $canPostLoanPayments, $paymentDate, $payrollReference, $defaultDeductionType
        ) {
            $rows = $batch->rows()->orderBy('row_number')->get();

            foreach ($rows as $row) {
                $summary['totalRows']++;

                if (in_array($row->row_number, $excludedRowNumbers, true)) {
                    $row->update(['excluded' => true, 'row_status' => 'Skipped']);
                    $summary['skippedRows']++;

                    continue;
                }

                // A row present in resolvedMatches means the conflict-resolution
                // dialog explicitly picked a member for it — that's the user
                // overriding an "Invalid: Duplicate Member" block, so it bypasses
                // the category gate below rather than being auto-failed.
                $hasResolution = array_key_exists($row->row_number, $resolvedMatches);

                if (! $hasResolution) {
                    if ($row->validation_category === 'Invalid') {
                        $row->update(['row_status' => 'Failed']);
                        $summary['errors']++;

                        continue;
                    }
                    if ($row->validation_category === 'Warning') {
                        $summary['warnings']++;
                        if (! $includeWarnings) {
                            $row->update(['row_status' => 'Skipped']);
                            $summary['skippedRows']++;

                            continue;
                        }
                    }
                }

                $memberId = $resolvedMatches[$row->row_number] ?? $row->matched_member_id;
                $member = $memberId ? Member::find($memberId) : null;
                if (! $member) {
                    $row->update(['row_status' => 'Failed']);
                    $summary['errors']++;

                    continue;
                }

                $data = $row->raw_data;
                $principal = $this->parseAmount($data['principal'] ?? null) ?? 0.0;
                $interest = $this->parseAmount($data['interest'] ?? null) ?? 0.0;
                $monthlyDues = $this->parseAmount($data['monthly_dues'] ?? null) ?? 0.0;
                $pabaon = $this->parseAmount($data['pabaon'] ?? null) ?? 0.0;

                $rowNotes = [];
                $createdLoanPaymentId = null;
                $createdContributionId = null;
                $createdDeductionId = null;
                $loanBefore = null;
                $loanAfter = null;

                if ($principal > 0 || $interest > 0) {
                    if (! $canPostLoanPayments) {
                        $rowNotes[] = 'Loan payment skipped — user lacks loan_payments.import permission.';
                    } else {
                        $loan = $row->matched_loan_id ? Loan::lockForUpdate()->find($row->matched_loan_id) : null;
                        if (! $loan) {
                            $rowNotes[] = 'Loan payment not posted — no matched loan.';
                        } else {
                            try {
                                $result = $this->poster->post($loan, [
                                    'amountPaid' => $principal + $interest,
                                    'penalty' => 0,
                                    'principalPortion' => $principal,
                                    'interestPortion' => $interest,
                                ], [
                                    'paymentDate' => $paymentDate,
                                    'paymentMethod' => 'Payroll Deduction',
                                    'payrollReference' => $payrollReference,
                                    'officialReceiptNumber' => 'PR-'.$batch->payroll_period.'-'.str_pad((string) $row->row_number, 4, '0', STR_PAD_LEFT),
                                    'receivedBy' => $user->full_name,
                                ]);
                                $createdLoanPaymentId = (string) $result->payment->id;
                                $loanBefore = ['p' => $result->principalBalanceBefore, 'i' => $result->interestBalanceBefore, 's' => $result->statusBefore];
                                $loanAfter = ['p' => $result->principalBalanceAfter, 'i' => $result->interestBalanceAfter, 's' => $result->statusAfter];
                                $rowNotes = array_merge($rowNotes, $result->warnings);
                                $summary['loanPaymentsImported']++;
                                $loanPaymentsAmount += $principal + $interest;
                            } catch (LoanPaymentPostingException $e) {
                                $rowNotes[] = 'Loan payment not posted: '.$e->getMessage();
                            }
                        }
                    }
                }

                if ($monthlyDues > 0) {
                    $existing = Contribution::query()
                        ->where('member_id', $member->id)
                        ->where('contribution_period', $batch->payroll_period)
                        ->where('status', 'Posted')
                        ->first();
                    if ($existing) {
                        $rowNotes[] = 'Contribution skipped — one already exists for this member and period.';
                    } else {
                        $contribution = Contribution::create([
                            'member_id' => $member->id,
                            'contribution_period' => $batch->payroll_period,
                            'amount' => $monthlyDues,
                            'payment_date' => $paymentDate,
                            'payment_method' => 'Payroll Deduction',
                            'payroll_reference' => $payrollReference,
                            'encoded_by' => $user->full_name,
                            'status' => 'Posted',
                        ]);
                        $contribution->update(['reference_number' => 'GCGEA-CON-'.now()->year.'-'.str_pad((string) $contribution->id, 6, '0', STR_PAD_LEFT)]);
                        $createdContributionId = (string) $contribution->id;
                        $summary['contributionsCreated']++;
                        $contributionsAmount += $monthlyDues;
                    }
                }

                if ($pabaon > 0) {
                    if (! $defaultDeductionType) {
                        $rowNotes[] = 'Pabaon skipped — no deduction type is configured.';
                    } else {
                        $deduction = Deduction::create([
                            'member_id' => $member->id,
                            'deduction_type_id' => $defaultDeductionType->id,
                            'period' => $batch->payroll_period,
                            'amount' => $pabaon,
                            'payment_date' => $paymentDate,
                            'payroll_reference' => $payrollReference,
                            'encoded_by' => $user->full_name,
                            'status' => 'Posted',
                        ]);
                        $deduction->update(['reference_number' => 'GCGEA-DED-'.now()->year.'-'.str_pad((string) $deduction->id, 6, '0', STR_PAD_LEFT)]);
                        $createdDeductionId = (string) $deduction->id;
                        $summary['deductionsCreated']++;
                        $deductionsAmount += $pabaon;
                    }
                }

                $row->update([
                    'matched_member_id' => (string) $member->id,
                    'row_status' => 'Imported',
                    'created_loan_payment_id' => $createdLoanPaymentId,
                    'created_contribution_id' => $createdContributionId,
                    'created_deduction_id' => $createdDeductionId,
                    'loan_principal_balance_before' => $loanBefore['p'] ?? null,
                    'loan_interest_balance_before' => $loanBefore['i'] ?? null,
                    'loan_status_before' => $loanBefore['s'] ?? null,
                    'loan_principal_balance_after' => $loanAfter['p'] ?? null,
                    'loan_interest_balance_after' => $loanAfter['i'] ?? null,
                    'loan_status_after' => $loanAfter['s'] ?? null,
                    'validation_reasons' => array_merge($row->validation_reasons ?? [], $rowNotes),
                ]);
                $summary['importedMembers']++;
            }

            $summary['totalPayrollDeduction'] = round($loanPaymentsAmount + $contributionsAmount + $deductionsAmount, 2);

            $batch->update([
                'status' => 'Committed',
                'committed_by_user_id' => $user->id,
                'committed_at' => now(),
                'imported_rows' => $summary['importedMembers'],
                'skipped_rows' => $summary['skippedRows'],
                'error_rows' => $summary['errors'],
                'total_loan_payments' => round($loanPaymentsAmount, 2),
                'total_contributions' => round($contributionsAmount, 2),
                'total_deductions' => round($deductionsAmount, 2),
            ]);

            $this->auditLog->record($user, $batch, 'commit', "Committed payroll import for {$batch->payroll_period} ({$summary['importedMembers']} row(s) imported).");
        });
    }

    /**
     * Reverses a committed batch — but only if every affected record is
     * still exactly in the state this import left it in. All-or-nothing per
     * batch: if anything drifted, nothing is reversed.
     */
    public function rollback(PayrollImportBatch $batch, string $reason, User $user): void
    {
        if ($batch->status !== 'Committed') {
            throw new PayrollImportRollbackException('Only a committed payroll import batch can be rolled back.');
        }

        DB::transaction(function () use ($batch, $reason, $user) {
            $rows = $batch->rows()->where('row_status', 'Imported')->get();
            $drifted = [];
            $lockedLoans = [];

            foreach ($rows as $row) {
                if ($row->created_loan_payment_id) {
                    $payment = LoanPayment::find($row->created_loan_payment_id);
                    if (! $payment || $payment->status !== 'Posted') {
                        $drifted[] = "Row {$row->row_number}: the loan payment it created has already been voided or removed.";

                        continue;
                    }
                    $loan = Loan::lockForUpdate()->find($payment->loan_application_id);
                    if (! $loan
                        || abs(round((float) $loan->principal_balance, 2) - (float) $row->loan_principal_balance_after) > 0.01
                        || abs(round((float) $loan->interest_balance, 2) - (float) $row->loan_interest_balance_after) > 0.01
                        || $loan->status !== $row->loan_status_after) {
                        $drifted[] = "Row {$row->row_number}: the affected loan has changed since this import ran.";

                        continue;
                    }
                    $lockedLoans[$row->id] = $loan;
                }
                if ($row->created_contribution_id) {
                    $contribution = Contribution::find($row->created_contribution_id);
                    if (! $contribution || $contribution->status !== 'Posted') {
                        $drifted[] = "Row {$row->row_number}: the contribution it created has already been voided or removed.";
                    }
                }
                if ($row->created_deduction_id) {
                    $deduction = Deduction::find($row->created_deduction_id);
                    if (! $deduction || $deduction->status !== 'Posted') {
                        $drifted[] = "Row {$row->row_number}: the deduction it created has already been voided or removed.";
                    }
                }
            }

            if (count($drifted) > 0) {
                throw new PayrollImportRollbackException(implode(' ', $drifted));
            }

            foreach ($rows as $row) {
                if ($row->created_loan_payment_id) {
                    $payment = LoanPayment::find($row->created_loan_payment_id);
                    $payment->update(['status' => 'Voided', 'void_reason' => $reason]);
                    $loan = $lockedLoans[$row->id];
                    $loan->update([
                        'principal_balance' => $row->loan_principal_balance_before,
                        'interest_balance' => $row->loan_interest_balance_before,
                        'outstanding_balance' => round((float) $row->loan_principal_balance_before + (float) $row->loan_interest_balance_before, 2),
                        'status' => $row->loan_status_before,
                    ]);
                }
                if ($row->created_contribution_id) {
                    Contribution::find($row->created_contribution_id)?->update([
                        'status' => 'Voided', 'void_reason' => $reason, 'voided_by' => $user->full_name, 'voided_at' => now(),
                    ]);
                }
                if ($row->created_deduction_id) {
                    Deduction::find($row->created_deduction_id)?->update([
                        'status' => 'Voided', 'void_reason' => $reason, 'voided_by' => $user->full_name, 'voided_at' => now(),
                    ]);
                }
                $row->update(['row_status' => 'RolledBack']);
            }

            $batch->update([
                'status' => 'RolledBack',
                'rolled_back_by_user_id' => $user->id,
                'rolled_back_at' => now(),
                'rollback_reason' => $reason,
            ]);

            $this->auditLog->record($user, $batch, 'rollback', "Rolled back payroll import for {$batch->payroll_period}: {$reason}");
        });
    }

    /**
     * @param  array<string,mixed>  $rawRow  keyed by original spreadsheet header
     * @param  array<string,?string>  $mapping  targetField => spreadsheet header
     * @return array<string,mixed> keyed by target field
     */
    private function mapRow(array $rawRow, array $mapping): array
    {
        $mapped = [];
        foreach (PayrollColumnMapper::TARGET_FIELDS as $field => $label) {
            $header = $mapping[$field] ?? null;
            $value = $header !== null ? ($rawRow[$header] ?? null) : null;
            $mapped[$field] = is_string($value) ? trim($value) : $value;
        }

        return $mapped;
    }

    private function isFooterRow(array $mapped): bool
    {
        $name = strtoupper(trim((string) ($mapped['name'] ?? '')));

        return $name !== '' && in_array($name, self::FOOTER_MARKERS, true);
    }

    /**
     * @param  array<string,mixed>  $mapped
     * @return array{category: string, reasons: array<int,string>, memberId: ?string, memberName: ?string, matchMethod: ?string, matchCandidates: array<int,array<string,string>>, loanId: ?string}
     */
    private function validateRow(array $mapped): array
    {
        $reasons = [];
        $warnings = [];

        $principal = $this->parseAmount($mapped['principal'] ?? null);
        $interest = $this->parseAmount($mapped['interest'] ?? null);
        $monthlyDues = $this->parseAmount($mapped['monthly_dues'] ?? null);
        $pabaon = $this->parseAmount($mapped['pabaon'] ?? null);
        $totalRemit = $this->parseAmount($mapped['total_remit'] ?? null);
        $currentMonthPayment = $this->parseAmount($mapped['current_month_loan_payment'] ?? null);
        $principalPrev = $this->parseAmount($mapped['principal_balance_previous'] ?? null);
        $interestPrev = $this->parseAmount($mapped['interest_balance_previous'] ?? null);
        $principalCurrent = $this->parseAmount($mapped['principal_balance_current'] ?? null);
        $interestCurrent = $this->parseAmount($mapped['interest_balance_current'] ?? null);

        foreach (['principal' => $principal, 'interest' => $interest, 'monthly_dues' => $monthlyDues, 'pabaon' => $pabaon] as $field => $value) {
            if ($value !== null && $value < 0) {
                $reasons[] = 'Negative Amount ('.PayrollColumnMapper::TARGET_FIELDS[$field].')';
            }
        }

        $match = $this->matchMember($mapped);

        if ($match['status'] === 'unknown') {
            $reasons[] = 'Unknown Member';
        } elseif ($match['status'] === 'ambiguous') {
            $reasons[] = 'Duplicate Member — multiple members match this name';
        }

        $member = $match['member'];
        $loanId = null;

        if ($member instanceof Member) {
            if (in_array($member->membership_status, ['Inactive', 'Suspended', 'Terminated', 'Deceased'], true)) {
                $warnings[] = "Inactive Member ({$member->membership_status})";
            }

            $instance = $member->approvalInstance;
            if ($member->is_draft === false && $instance?->status === 'pending') {
                $warnings[] = 'Pending Registration';
            }
            if ($member->is_draft === true && in_array($instance?->status, ['rejected', 'returned'], true)) {
                $reasons[] = 'Rejected Registration';
            }

            $hasLoanPayment = ($principal ?? 0) > 0 || ($interest ?? 0) > 0;
            if ($hasLoanPayment) {
                $loan = Loan::query()->where('member_id', $member->id)
                    ->whereIn('status', self::POSTABLE_LOAN_STATUSES)
                    ->orderByDesc('created_at')
                    ->first();
                if (! $loan) {
                    $reasons[] = 'Missing Loan — no active loan to post this payment against';
                } else {
                    $loanId = (string) $loan->id;
                }
            }
        }

        if ($principalPrev !== null && $principal !== null && $principalCurrent !== null
            && abs(($principalPrev - $principal) - $principalCurrent) > self::TOLERANCE) {
            $warnings[] = 'Invalid Balance — previous principal balance minus principal payment does not equal current principal balance';
        }
        if ($interestPrev !== null && $interest !== null && $interestCurrent !== null
            && abs(($interestPrev - $interest) - $interestCurrent) > self::TOLERANCE) {
            $warnings[] = 'Invalid Balance — previous interest balance minus interest payment does not equal current interest balance';
        }

        if ($totalRemit !== null) {
            $sum = ($principal ?? 0) + ($interest ?? 0) + ($monthlyDues ?? 0) + ($pabaon ?? 0);
            if (abs($sum - $totalRemit) > self::TOLERANCE) {
                $warnings[] = 'Total Remit does not equal Principal + Interest + Monthly Dues + Pabaon';
            }
        }
        if ($currentMonthPayment !== null && $principal !== null && $interest !== null
            && abs(($principal + $interest) - $currentMonthPayment) > self::TOLERANCE) {
            $warnings[] = 'Current Month Loan Payment does not equal Principal + Interest';
        }

        $category = match (true) {
            count($reasons) > 0 => 'Invalid',
            count($warnings) > 0 => 'Warning',
            default => 'Valid',
        };

        return [
            'category' => $category,
            'reasons' => array_merge($reasons, $warnings),
            'memberId' => $member instanceof Member ? (string) $member->id : null,
            'memberName' => $member instanceof Member ? $member->full_name : ($mapped['name'] ?? null),
            'matchMethod' => $match['method'],
            'matchCandidates' => $match['candidates'],
            'loanId' => $loanId,
        ];
    }

    /**
     * Match order per spec: Member Number, then Employee Number, then Name.
     *
     * @return array{status: string, member: ?Member, method: ?string, candidates: array<int,array<string,string>>}
     */
    private function matchMember(array $mapped): array
    {
        $memberNumber = trim((string) ($mapped['member_number'] ?? ''));
        $employeeNumber = trim((string) ($mapped['employee_number'] ?? ''));
        $name = trim((string) ($mapped['name'] ?? ''));

        if ($memberNumber !== '') {
            $member = Member::query()->where('member_number', $memberNumber)->first();
            if ($member) {
                return ['status' => 'matched', 'member' => $member, 'method' => 'member_number', 'candidates' => []];
            }
        }

        if ($employeeNumber !== '') {
            $candidates = Member::query()->where('employee_number', $employeeNumber)->get();
            if ($candidates->count() === 1) {
                return ['status' => 'matched', 'member' => $candidates->first(), 'method' => 'employee_number', 'candidates' => []];
            }
            if ($candidates->count() > 1) {
                return ['status' => 'ambiguous', 'member' => null, 'method' => 'employee_number', 'candidates' => $this->toCandidateList($candidates)];
            }
        }

        if ($name !== '') {
            $needle = $this->normalizeNameTokens($name);
            $candidates = Member::query()->get()->filter(
                fn (Member $m) => $this->normalizeNameTokens($m->full_name) === $needle
            );
            if ($candidates->count() === 1) {
                return ['status' => 'matched', 'member' => $candidates->first(), 'method' => 'name', 'candidates' => []];
            }
            if ($candidates->count() > 1) {
                return ['status' => 'ambiguous', 'member' => null, 'method' => 'name', 'candidates' => $this->toCandidateList($candidates)];
            }
        }

        return ['status' => 'unknown', 'member' => null, 'method' => null, 'candidates' => []];
    }

    private function toCandidateList($members): array
    {
        return $members->map(fn (Member $m) => [
            'id' => (string) $m->id,
            'name' => $m->full_name,
            'memberNumber' => $m->member_number,
            'employeeNumber' => $m->employee_number,
            'officeName' => $m->office?->name,
        ])->values()->all();
    }

    /**
     * Word-order-independent name comparison so "Dela Cruz, Juan" (sheet) and
     * "Dela Cruz, Juan" / "Juan Dela Cruz" (member record) both match.
     */
    private function normalizeNameTokens(string $name): string
    {
        $letters = preg_replace('/[^A-Za-z\s]/', ' ', strtoupper($name)) ?? '';
        $tokens = array_values(array_filter(preg_split('/\s+/', trim($letters)) ?: []));
        sort($tokens);

        return implode(' ', $tokens);
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
