<?php

namespace App\Http\Controllers;

use App\Exceptions\PayrollImportRollbackException;
use App\Models\PayrollImportBatch;
use App\Services\AuditLogService;
use App\Services\PayrollColumnMapper;
use App\Services\PayrollImportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PayrollImportController extends Controller
{
    public function __construct(
        private readonly PayrollImportService $service,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * Step 1: upload the workbook, auto-detect its columns, stage a batch.
     * No business records are created here — parsing only.
     */
    public function upload(Request $request)
    {
        $data = $request->validate([
            // `mimes:xlsx,xls` sniffs the file's actual content-type via
            // fileinfo — on Windows this frequently misdetects a perfectly
            // valid .xlsx (a zip archive under the hood) as application/zip
            // or application/octet-stream and rejects it. Validate by
            // extension instead; PhpSpreadsheet throws its own clear error
            // later if the file turns out not to be a real workbook.
            'file' => ['required', 'file', 'extensions:xlsx,xls', 'max:10240'],
            'payrollPeriod' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'payrollReference' => ['nullable', 'string', 'max:255'],
        ]);

        $token = (string) Str::uuid();
        $storagePath = "payroll-imports/{$token}/original.xlsx";
        Storage::disk('local')->putFileAs("payroll-imports/{$token}", $request->file('file'), 'original.xlsx');

        $absolutePath = Storage::disk('local')->path($storagePath);
        $detection = $this->service->detectColumns($absolutePath);

        $batch = PayrollImportBatch::create([
            'token' => $token,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'storage_path' => $storagePath,
            'payroll_period' => $data['payrollPeriod'],
            'payroll_reference' => $data['payrollReference'] ?? null,
            'column_mapping' => $detection['detectedMapping'],
            'sheet_meta' => ['headerRowIndex' => $detection['headerRowIndex'], 'sheetName' => $detection['selectedSheet']],
            'status' => 'Uploaded',
            'uploaded_by_user_id' => $request->user()->id,
            'total_rows' => $detection['totalRows'],
        ]);

        $this->auditLog->record(
            $request->user(),
            $batch,
            'upload',
            "Uploaded payroll file '{$batch->original_filename}' for period {$batch->payroll_period} ({$detection['totalRows']} row(s) detected)."
        );

        return response()->json([
            'token' => $batch->token,
            'targetFields' => PayrollColumnMapper::TARGET_FIELDS,
            'requiredFields' => PayrollColumnMapper::REQUIRED_FIELDS,
            'headers' => $detection['headers'],
            'detectedMapping' => $detection['detectedMapping'],
            'unmatchedHeaders' => $detection['unmatchedHeaders'],
            'sampleRows' => $detection['sampleRows'],
            'totalRows' => $detection['totalRows'],
            'sheetNames' => $detection['sheetNames'],
            'selectedSheet' => $detection['selectedSheet'],
        ]);
    }

    /**
     * Switches which worksheet of an already-uploaded workbook is used —
     * payroll files often carry one tab per office/department alongside
     * summary tabs. Only allowed pre-preview; re-detects headers/mapping/
     * sample rows against the newly selected sheet.
     */
    public function selectSheet(Request $request, PayrollImportBatch $batch)
    {
        $this->assertOwnedBatch($batch);
        if ($batch->status !== 'Uploaded') {
            abort(422, 'The worksheet can only be changed before the batch is previewed.');
        }

        $data = $request->validate(['sheet' => ['required', 'string']]);

        $absolutePath = Storage::disk('local')->path($batch->storage_path);
        $detection = $this->service->detectColumns($absolutePath, $data['sheet']);

        $batch->update([
            'column_mapping' => $detection['detectedMapping'],
            'sheet_meta' => ['headerRowIndex' => $detection['headerRowIndex'], 'sheetName' => $detection['selectedSheet']],
            'total_rows' => $detection['totalRows'],
        ]);

        return response()->json([
            'token' => $batch->token,
            'targetFields' => PayrollColumnMapper::TARGET_FIELDS,
            'requiredFields' => PayrollColumnMapper::REQUIRED_FIELDS,
            'headers' => $detection['headers'],
            'detectedMapping' => $detection['detectedMapping'],
            'unmatchedHeaders' => $detection['unmatchedHeaders'],
            'sampleRows' => $detection['sampleRows'],
            'totalRows' => $detection['totalRows'],
            'sheetNames' => $detection['sheetNames'],
            'selectedSheet' => $detection['selectedSheet'],
        ]);
    }

    /**
     * Step 2: apply the (possibly user-edited) mapping, validate every row,
     * and persist the results so commit can re-validate from stored rows
     * rather than trusting client-supplied amounts.
     */
    public function preview(Request $request, PayrollImportBatch $batch)
    {
        $this->assertOwnedBatch($batch);

        $data = $request->validate([
            'mapping' => ['required', 'array'],
        ]);

        $mapping = array_merge(array_fill_keys(array_keys(PayrollColumnMapper::TARGET_FIELDS), null), $data['mapping']);

        $absolutePath = Storage::disk('local')->path($batch->storage_path);
        $result = $this->service->validate($absolutePath, $mapping, $batch, $batch->sheet_meta['sheetName'] ?? null);

        $batch->rows()->delete();
        foreach ($result['rows'] as $row) {
            $batch->rows()->create([
                'row_number' => $row['rowNumber'],
                'raw_data' => $row['data'],
                'matched_member_id' => $row['memberId'],
                'matched_loan_id' => $row['loanId'],
                'match_method' => $row['matchMethod'],
                'validation_category' => $row['category'],
                'validation_reasons' => $row['reasons'],
                'row_status' => 'Pending',
            ]);
        }

        $batch->update([
            'column_mapping' => $mapping,
            'status' => 'Previewed',
            'total_rows' => $result['summary']['total'],
            'error_rows' => $result['summary']['invalid'],
        ]);

        $this->auditLog->record(
            $request->user(),
            $batch,
            'validate',
            "Validated payroll import for {$batch->payroll_period}: {$result['summary']['valid']} valid, ".
                "{$result['summary']['warning']} warning, {$result['summary']['invalid']} invalid row(s)."
        );

        return response()->json([
            'token' => $batch->token,
            'rows' => $result['rows'],
            'summary' => $result['summary'],
            'batchWarnings' => $result['batchWarnings'],
        ]);
    }

    /**
     * Step 3: create the actual records. Re-validates from the stored rows,
     * not client-supplied amounts — the request only carries which rows to
     * exclude, conflict-dialog resolutions, and whether to include warnings.
     */
    public function commit(Request $request, PayrollImportBatch $batch)
    {
        $this->assertOwnedBatch($batch);
        if ($batch->status !== 'Previewed') {
            abort(422, 'This batch must be previewed before it can be committed.');
        }

        $data = $request->validate([
            'resolvedMatches' => ['sometimes', 'array'],
            'excludedRows' => ['sometimes', 'array'],
            'excludedRows.*' => ['integer'],
            'includeWarnings' => ['sometimes', 'boolean'],
        ]);

        $resolvedMatches = [];
        foreach ($data['resolvedMatches'] ?? [] as $rowNumber => $memberId) {
            $resolvedMatches[(int) $rowNumber] = (string) $memberId;
        }

        $summary = $this->service->commit(
            $batch,
            $resolvedMatches,
            $data['excludedRows'] ?? [],
            $data['includeWarnings'] ?? true,
            $request->user(),
        );

        return response()->json(['token' => $batch->token, 'summary' => $summary]);
    }

    public function rollback(Request $request, PayrollImportBatch $batch)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $this->service->rollback($batch, $data['reason'], $request->user());
        } catch (PayrollImportRollbackException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($this->summarize($batch->fresh()));
    }

    public function downloadReport(PayrollImportBatch $batch): Response
    {
        $batch->load('rows');

        $lines = ['Row,Member,Category,Reasons,Loan Payment ID,Contribution ID,Deduction ID,Row Status'];
        foreach ($batch->rows as $row) {
            $reasons = implode('; ', $row->validation_reasons ?? []);
            $memberName = $row->raw_data['name'] ?? '';
            $fields = [
                $row->row_number, $memberName, $row->validation_category, $reasons,
                $row->created_loan_payment_id, $row->created_contribution_id, $row->created_deduction_id, $row->row_status,
            ];
            $lines[] = implode(',', array_map(fn ($f) => '"'.str_replace('"', '""', (string) ($f ?? '')).'"', $fields));
        }

        $csv = implode("\r\n", $lines);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="payroll-import-'.$batch->payroll_period.'-'.$batch->token.'.csv"',
        ]);
    }

    public function index(Request $request)
    {
        $query = PayrollImportBatch::with(['uploadedBy', 'committedBy']);

        if ($period = $request->string('period')->toString()) {
            $query->where('payroll_period', $period);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        $paginator = $query->orderByDesc('created_at')->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (PayrollImportBatch $b) => $this->summarize($b)),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'totalRecords' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(PayrollImportBatch $batch)
    {
        $batch->load(['rows', 'uploadedBy', 'committedBy', 'rolledBackBy']);

        return response()->json(array_merge($this->summarize($batch), [
            'columnMapping' => $batch->column_mapping,
            'rows' => $batch->rows->map(fn ($r) => [
                'rowNumber' => $r->row_number,
                'data' => $r->raw_data,
                'memberId' => $r->matched_member_id,
                'loanId' => $r->matched_loan_id,
                'matchMethod' => $r->match_method,
                'category' => $r->validation_category,
                'reasons' => $r->validation_reasons,
                'excluded' => (bool) $r->excluded,
                'rowStatus' => $r->row_status,
            ]),
        ]));
    }

    private function summarize(PayrollImportBatch $batch): array
    {
        return [
            'id' => (string) $batch->id,
            'token' => $batch->token,
            'payrollPeriod' => $batch->payroll_period,
            'payrollReference' => $batch->payroll_reference,
            'uploadedBy' => $batch->uploadedBy?->full_name,
            'importDate' => $batch->created_at?->toIso8601String(),
            'totalRows' => $batch->total_rows,
            'importedRows' => $batch->imported_rows,
            'skippedRows' => $batch->skipped_rows,
            'errorRows' => $batch->error_rows,
            // Record counts (not amounts) — matches the spec's "Loan Payments
            // Imported / Contribution Records Created / Pabaon Records
            // Created" summary fields. The batch's total_loan_payments /
            // total_contributions / total_deductions columns hold the peso
            // amounts instead, used for totalPayrollDeduction and reporting.
            'loanPaymentsImported' => $batch->rows()->whereNotNull('created_loan_payment_id')->count(),
            'contributionsCreated' => $batch->rows()->whereNotNull('created_contribution_id')->count(),
            'deductionsCreated' => $batch->rows()->whereNotNull('created_deduction_id')->count(),
            'totalPayrollDeduction' => round((float) $batch->total_loan_payments + (float) $batch->total_contributions + (float) $batch->total_deductions, 2),
            'status' => $batch->status,
            'committedBy' => $batch->committedBy?->full_name,
            'committedAt' => $batch->committed_at?->toIso8601String(),
            'rolledBackBy' => $batch->rolledBackBy?->full_name,
            'rolledBackAt' => $batch->rolled_back_at?->toIso8601String(),
            'rollbackReason' => $batch->rollback_reason,
        ];
    }

    private function assertOwnedBatch(PayrollImportBatch $batch): void
    {
        if (in_array($batch->status, ['RolledBack'], true)) {
            abort(422, 'This payroll import batch has been rolled back and can no longer be modified.');
        }
    }
}
