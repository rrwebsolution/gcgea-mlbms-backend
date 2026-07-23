<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberImportBatch;
use App\Models\MemberImportRow;
use App\Models\Office;
use App\Services\MemberColumnMapper;
use App\Services\MemberImportService;
use App\Services\OfficeResolutionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MemberImportController extends Controller
{
    public function __construct(
        private readonly MemberImportService $service,
        private readonly OfficeResolutionService $officeResolver,
    ) {}

    /**
     * Step 1: upload the workbook and list its worksheets. No column
     * detection yet — headers can differ per sheet, so that only runs once
     * a worksheet is chosen (selectSheet).
     */
    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:10240'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $token = (string) Str::uuid();
        $storagePath = "member-imports/{$token}/original.{$ext}";
        Storage::disk('local')->putFileAs("member-imports/{$token}", $file, "original.{$ext}");

        $absolutePath = Storage::disk('local')->path($storagePath);
        $worksheets = $this->service->listWorksheets($absolutePath, $ext);

        $batch = MemberImportBatch::create([
            'token' => $token,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'source_file_ext' => $ext,
            'status' => 'Uploaded',
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'token' => $batch->token,
            'sourceFileExt' => $ext,
            'worksheets' => $worksheets,
        ]);
    }

    /**
     * Step 2/3: pick a worksheet (a no-op choice for CSV, which has one
     * implicit sheet) and detect its header row + suggested column mapping.
     */
    public function selectSheet(Request $request, MemberImportBatch $batch)
    {
        $this->assertNotCommitted($batch);

        $data = $request->validate([
            'sheetName' => ['nullable', 'string'],
        ]);

        $absolutePath = Storage::disk('local')->path($batch->storage_path);
        $detection = $this->service->detectColumns($absolutePath, $batch->source_file_ext, $data['sheetName'] ?? null);

        $batch->update([
            'selected_sheet_name' => $data['sheetName'] ?? null,
            'column_mapping' => $detection['detectedMapping'],
            'sheet_meta' => ['headerRowIndex' => $detection['headerRowIndex']],
            'status' => 'SheetSelected',
            'total_rows' => $detection['totalRows'],
        ]);

        return response()->json([
            'token' => $batch->token,
            'targetFields' => MemberColumnMapper::TARGET_FIELDS,
            'requiredFields' => MemberColumnMapper::REQUIRED_FIELDS,
            'headerRowIndex' => $detection['headerRowIndex'],
            'headers' => $detection['headers'],
            'detectedMapping' => $detection['detectedMapping'],
            'unmatchedHeaders' => $detection['unmatchedHeaders'],
            'sampleRows' => $detection['sampleRows'],
            'totalRows' => $detection['totalRows'],
        ]);
    }

    /**
     * Step 4/5/6: apply the (possibly edited) mapping, validate + clean +
     * resolve offices + score duplicates for every row, and persist the
     * results so commit can re-validate from stored rows.
     */
    public function preview(Request $request, MemberImportBatch $batch)
    {
        $this->assertNotCommitted($batch);

        $data = $request->validate([
            'mapping' => ['required', 'array'],
        ]);

        $mapping = array_merge(array_fill_keys(array_keys(MemberColumnMapper::TARGET_FIELDS), null), $data['mapping']);

        $absolutePath = Storage::disk('local')->path($batch->storage_path);
        $result = $this->service->validate($absolutePath, $batch->source_file_ext, $batch->selected_sheet_name, $mapping);

        $batch->rows()->delete();
        foreach ($result['rows'] as $row) {
            $batch->rows()->create([
                'row_number' => $row['rowNumber'],
                'raw_data' => $row['data'],
                'validation_category' => $row['category'],
                'validation_reasons' => $row['reasons'],
                'duplicate_score' => $row['duplicateScore'],
                'duplicate_candidate_member_ids' => $row['duplicateCandidates'],
                'resolved_office_id' => $row['resolvedOfficeId'],
                'unresolved_office_text' => $row['unresolvedOfficeText'],
                'row_status' => 'Pending',
            ]);
        }

        $batch->update([
            'column_mapping' => $mapping,
            'status' => 'Previewed',
            'total_rows' => $result['summary']['total'],
            'error_rows' => $result['summary']['invalid'],
        ]);

        return response()->json([
            'token' => $batch->token,
            'rows' => $result['rows'],
            'summary' => $result['summary'],
            'unresolvedOffices' => $this->officeResolver->groupUnresolved($batch->id),
        ]);
    }

    /**
     * Step 7: resolve one distinct unmapped office spelling for every row
     * it appears in — pick an existing office, create a new one, and
     * optionally remember the mapping for future imports.
     */
    public function resolveOffice(Request $request, MemberImportBatch $batch)
    {
        $this->assertNotCommitted($batch);

        $data = $request->validate([
            'rawText' => ['required', 'string'],
            'officeId' => ['nullable', 'exists:offices,id'],
            'newOffice' => ['nullable', 'array'],
            'newOffice.code' => ['required_with:newOffice', 'string', 'max:50', 'unique:offices,code'],
            'newOffice.name' => ['required_with:newOffice', 'string', 'max:255'],
            'saveAsAlias' => ['sometimes', 'boolean'],
        ]);

        $officeId = $data['officeId'] ?? null;
        if (! $officeId && ! empty($data['newOffice'])) {
            $office = Office::create([
                'code' => $data['newOffice']['code'],
                'name' => $data['newOffice']['name'],
                'status' => 'Active',
            ]);
            $officeId = $office->id;
        }

        if (! $officeId) {
            abort(422, 'Select an existing office or provide a new office to create.');
        }

        $affected = $this->officeResolver->applyResolutionToBatch($batch->id, $data['rawText'], $officeId);

        if ($data['saveAsAlias'] ?? false) {
            $this->officeResolver->saveAlias($data['rawText'], $officeId, $request->user());
        }

        return response()->json([
            'token' => $batch->token,
            'rowsResolved' => $affected,
            'unresolvedOffices' => $this->officeResolver->groupUnresolved($batch->id),
        ]);
    }

    /**
     * Step 8: record a create-new/skip/merge decision for ambiguous
     * (Probable/Possible/Exact) duplicate-match rows.
     */
    public function resolveDuplicates(Request $request, MemberImportBatch $batch)
    {
        $this->assertNotCommitted($batch);

        $data = $request->validate([
            'resolutions' => ['required', 'array'],
        ]);

        foreach ($data['resolutions'] as $rowNumber => $action) {
            $batch->rows()->where('row_number', (int) $rowNumber)->update(['resolved_action' => $action]);
        }

        return response()->json(['token' => $batch->token, 'updated' => count($data['resolutions'])]);
    }

    /**
     * Step 11: create the actual records. Re-validates from stored rows —
     * `resolutions` here only carries any last-minute overrides not already
     * persisted via resolveDuplicates.
     */
    public function commit(Request $request, MemberImportBatch $batch)
    {
        $this->assertNotCommitted($batch);
        if ($batch->status !== 'Previewed') {
            abort(422, 'This batch must be previewed before it can be committed.');
        }

        $data = $request->validate([
            'resolutions' => ['sometimes', 'array'],
        ]);

        $resolutions = [];
        foreach ($data['resolutions'] ?? [] as $rowNumber => $action) {
            $resolutions[(int) $rowNumber] = (string) $action;
        }

        $summary = $this->service->commit($batch, $resolutions, $request->user());

        return response()->json(['token' => $batch->token, 'summary' => $summary]);
    }

    /**
     * Members created by an import that are still awaiting registration
     * approval — the queue at /members/imported-pending-review. Joined with
     * their originating batch/row for source-worksheet context the plain
     * members list doesn't carry.
     */
    public function pendingReview(Request $request)
    {
        $query = Member::with(['office', 'approvalInstance'])
            ->whereNotNull('imported_from_batch_id')
            ->where('membership_status', 'Pending')
            ->where('is_archived', false);

        if ($search = $request->string('search')->toString()) {
            $query->where(fn ($q) => $q->where('surname', 'ilike', "%{$search}%")->orWhere('first_name', 'ilike', "%{$search}%"));
        }

        $query->orderByDesc('created_at');
        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        $memberIds = collect($paginator->items())->pluck('id');
        $batchIds = collect($paginator->items())->pluck('imported_from_batch_id')->unique()->filter();
        $batches = MemberImportBatch::whereIn('id', $batchIds)->with('uploadedBy')->get()->keyBy('id');
        $rows = MemberImportRow::whereIn('created_member_id', $memberIds)->get()->keyBy('created_member_id');

        $data = collect($paginator->items())->map(function (Member $member) use ($batches, $rows) {
            $batch = $batches->get($member->imported_from_batch_id);
            $row = $rows->get($member->id);

            return [
                'id' => (string) $member->id,
                'fullName' => $member->full_name,
                'officeName' => $member->office?->name,
                'sourceWorksheet' => $batch?->selected_sheet_name,
                'sourceRow' => $row?->row_number,
                'importDate' => $member->created_at?->toIso8601String(),
                'importedBy' => $batch?->uploadedBy?->full_name,
                'validationWarnings' => $row?->validation_reasons ?? [],
                'approvalStatus' => $member->approvalInstance?->status,
                'membershipStatus' => $member->membership_status,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'totalRecords' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = MemberImportBatch::with(['uploadedBy', 'committedBy']);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        $paginator = $query->orderByDesc('created_at')->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (MemberImportBatch $b) => $this->summarize($b)),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'totalRecords' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(MemberImportBatch $batch)
    {
        $batch->load(['rows', 'legacyLoanImports', 'uploadedBy', 'committedBy']);

        return response()->json(array_merge($this->summarize($batch), [
            'selectedSheetName' => $batch->selected_sheet_name,
            'columnMapping' => $batch->column_mapping,
            'legacyLoans' => $batch->legacyLoanImports->map(fn ($l) => [
                'id' => (string) $l->id,
                'memberImportRowId' => (string) $l->member_import_row_id,
                'createdMemberId' => $l->created_member_id ? (string) $l->created_member_id : null,
                'cashPabaon' => $l->cash_pabaon,
                'cashPabaonAmount' => $l->cash_pabaon_amount !== null ? (float) $l->cash_pabaon_amount : null,
                'loanStart' => $l->loan_start,
                'loanStartDate' => $l->loan_start_date?->toDateString(),
                'solidarityAssistanceLoan' => $l->solidarity_assistance_loan,
                'solidarityAssistanceLoanAmount' => $l->solidarity_assistance_loan_amount !== null ? (float) $l->solidarity_assistance_loan_amount : null,
                'noOfMonths' => $l->no_of_months,
                'noOfMonthsParsed' => $l->no_of_months_parsed,
                'monthlyAmort' => $l->monthly_amort,
                'monthlyAmortAmount' => $l->monthly_amort_amount !== null ? (float) $l->monthly_amort_amount : null,
                'legacyNotes' => $l->legacy_notes,
                'reviewStatus' => $l->review_status,
            ]),
            'rows' => $batch->rows->map(fn ($r) => [
                'rowNumber' => $r->row_number,
                'data' => $r->raw_data,
                'category' => $r->validation_category,
                'reasons' => $r->validation_reasons,
                'duplicateScore' => $r->duplicate_score,
                'duplicateCandidates' => $r->duplicate_candidate_member_ids,
                'resolvedOfficeId' => $r->resolved_office_id,
                'unresolvedOfficeText' => $r->unresolved_office_text,
                'resolvedAction' => $r->resolved_action,
                'rowStatus' => $r->row_status,
                'createdMemberId' => $r->created_member_id,
            ]),
        ]));
    }

    public function downloadReport(MemberImportBatch $batch): Response
    {
        $batch->load('rows');

        $lines = ['Row,Name,Category,Reasons,Row Status,Created Member ID'];
        foreach ($batch->rows as $row) {
            $name = trim(($row->raw_data['first_name'] ?? '').' '.($row->raw_data['last_name'] ?? ''));
            $reasons = implode('; ', $row->validation_reasons ?? []);
            $fields = [$row->row_number, $name, $row->validation_category, $reasons, $row->row_status, $row->created_member_id];
            $lines[] = implode(',', array_map(fn ($f) => '"'.str_replace('"', '""', (string) ($f ?? '')).'"', $fields));
        }

        return response(implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="member-import-'.$batch->token.'.csv"',
        ]);
    }

    private function summarize(MemberImportBatch $batch): array
    {
        return [
            'id' => (string) $batch->id,
            'token' => $batch->token,
            'originalFilename' => $batch->original_filename,
            'sourceFileExt' => $batch->source_file_ext,
            'uploadedBy' => $batch->uploadedBy?->full_name,
            'importDate' => $batch->created_at?->toIso8601String(),
            'totalRows' => $batch->total_rows,
            'importedRows' => $batch->imported_rows,
            'pendingReviewRows' => $batch->pending_review_rows,
            'skippedRows' => $batch->skipped_rows,
            'errorRows' => $batch->error_rows,
            'legacyLoanFlaggedRows' => $batch->legacy_loan_flagged_rows,
            'status' => $batch->status,
            'committedBy' => $batch->committedBy?->full_name,
            'committedAt' => $batch->committed_at?->toIso8601String(),
        ];
    }

    private function assertNotCommitted(MemberImportBatch $batch): void
    {
        if ($batch->status === 'Committed') {
            abort(422, 'This member import batch has already been committed and can no longer be modified.');
        }
    }
}
