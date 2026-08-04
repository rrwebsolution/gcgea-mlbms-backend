<?php

namespace App\Http\Controllers;

use App\Models\AnnualBudget;
use App\Services\ApprovalWorkflowService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnnualBudgetController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly ApprovalWorkflowService $workflow,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeView($request);
        $perPage = max(1, min(100, $request->integer('perPage', 10)));
        $query = AnnualBudget::query()->with(['items.disbursements', 'approvalInstance.currentStage'])->orderByDesc('fiscal_year');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AnnualBudget $budget) => $this->resource($budget))->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'totalRecords' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ]);
    }

    /** Full, unpaginated list — backs the Annual Budgets page's client-side search/filter/pagination. */
    public function all(Request $request)
    {
        $this->authorizeView($request);

        $budgets = AnnualBudget::query()
            ->with(['items.disbursements', 'approvalInstance.currentStage'])
            ->orderByDesc('fiscal_year')
            ->get();

        return response()->json($budgets->map(fn (AnnualBudget $budget) => $this->resource($budget))->values());
    }

    public function show(Request $request, int $year)
    {
        $this->authorizeView($request);
        abort_if($year < 2000 || $year > 2100, 422, 'The fiscal year must be between 2000 and 2100.');

        $budget = AnnualBudget::query()->with(['items.disbursements', 'approvalInstance.currentStage'])->where('fiscal_year', $year)->first();

        return response()->json($budget
            ? $this->resource($budget)
            : $this->emptyResource($year));
    }

    public function showById(Request $request, AnnualBudget $annualBudget)
    {
        $this->authorizeView($request);

        return response()->json($this->resource($annualBudget->load(['items.disbursements', 'approvalInstance.currentStage'])));
    }

    public function update(Request $request, int $year)
    {
        $this->authorizeManage($request);
        abort_if($year < 2000 || $year > 2100, 422, 'The fiscal year must be between 2000 and 2100.');

        $data = $request->validate([
            'estimatedRevenue' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'status' => ['nullable', Rule::in(['Draft'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array'],
            'items.*.accountTitle' => ['required', 'string', 'max:255'],
            'items.*.proposedAmount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ]);

        $budget = DB::transaction(function () use ($data, $request, $year): AnnualBudget {
            $budget = AnnualBudget::query()->firstOrNew(['fiscal_year' => $year]);
            $budget->fill([
                'estimated_revenue' => $data['estimatedRevenue'],
                'status' => 'Draft',
                'notes' => $data['notes'] ?? null,
                'prepared_by' => $budget->prepared_by ?: $request->user()->full_name,
                'approved_by' => null,
                'approved_at' => null,
                'rejection_reason' => null,
            ]);
            $budget->save();
            $budget->items()->delete();
            $budget->items()->createMany(collect($data['items'])->values()->map(
                fn (array $item, int $index) => [
                    'account_title' => trim($item['accountTitle']),
                    'proposed_amount' => $item['proposedAmount'],
                    'display_order' => $index,
                ]
            )->all());

            return $budget->fresh('items');
        });

        $this->auditLog->record($request->user(), $budget, 'annual_budget_saved', "Fiscal year {$year} budget draft saved.");

        return response()->json($this->resource($budget));
    }

    public function submit(Request $request, int $year)
    {
        abort_unless($request->user()->hasPermission('annual_budgets.submit'), 403);
        $budget = AnnualBudget::query()->where('fiscal_year', $year)->firstOrFail();
        abort_unless(in_array($budget->status, ['Draft', 'Rejected'], true), 422, 'Only Draft or Rejected budgets can be submitted.');
        abort_if($budget->items()->count() === 0, 422, 'Add at least one expenditure account before submitting.');
        abort_if((float) $budget->items()->sum('proposed_amount') > (float) $budget->estimated_revenue, 422, 'The proposed budget exceeds estimated annual revenue.');

        $this->workflow->startInstance($budget, 'annual_budget', $request->user());

        return response()->json($this->resource($budget->fresh(['items', 'approvalInstance.currentStage'])));
    }

    public function copyPrevious(Request $request, int $year)
    {
        $this->authorizeManage($request);
        abort_if($year < 2001 || $year > 2100, 422, 'The fiscal year must be between 2001 and 2100.');
        abort_if(AnnualBudget::query()->where('fiscal_year', $year)->exists(), 422, "A budget for {$year} already exists.");

        $source = AnnualBudget::query()->with('items')->where('fiscal_year', $year - 1)->first();
        abort_unless($source, 422, 'No previous-year budget is available to copy.');

        $budget = DB::transaction(function () use ($request, $source, $year): AnnualBudget {
            $budget = AnnualBudget::create([
                'fiscal_year' => $year,
                'estimated_revenue' => $source->estimated_revenue,
                'status' => 'Draft',
                'notes' => "Copied from fiscal year {$source->fiscal_year}.",
                'prepared_by' => $request->user()->full_name,
            ]);
            $budget->items()->createMany($source->items->map(fn ($item) => [
                'account_title' => $item->account_title,
                'proposed_amount' => $item->proposed_amount,
                'display_order' => $item->display_order,
            ])->all());

            return $budget->fresh('items');
        });

        $this->auditLog->record($request->user(), $budget, 'annual_budget_copied', 'Copied from fiscal year '.($year - 1).'.');

        return response()->json($this->resource($budget), 201);
    }

    private function resource(AnnualBudget $budget): array
    {
        $total = round((float) $budget->items->sum('proposed_amount'), 2);

        return [
            'id' => (string) $budget->id,
            'fiscalYear' => $budget->fiscal_year,
            'estimatedRevenue' => (float) $budget->estimated_revenue,
            'status' => $budget->status,
            'notes' => $budget->notes,
            'preparedBy' => $budget->prepared_by,
            'approvedBy' => $budget->approved_by,
            'approvedAt' => $budget->approved_at?->toIso8601String(),
            'rejectionReason' => $budget->rejection_reason,
            'currentStageLabel' => $budget->approvalInstance?->currentStage?->label,
            'items' => $budget->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'accountTitle' => $item->account_title,
                'proposedAmount' => (float) $item->proposed_amount,
                'committedAmount' => (float) $item->disbursements->whereNotIn('status', ['Rejected', 'Voided'])->sum('amount'),
                'actualPaidAmount' => (float) $item->disbursements->where('status', 'Paid')->sum('amount'),
                'remainingAmount' => round((float) $item->proposed_amount - (float) $item->disbursements->whereNotIn('status', ['Rejected', 'Voided'])->sum('amount'), 2),
            ])->values(),
            'totalProposedBudget' => $total,
            'unallocatedBalance' => round((float) $budget->estimated_revenue - $total, 2),
            'exists' => true,
        ];
    }

    private function emptyResource(int $year): array
    {
        return [
            'id' => null,
            'fiscalYear' => $year,
            'estimatedRevenue' => 0,
            'status' => 'Draft',
            'notes' => null,
            'preparedBy' => null,
            'approvedBy' => null,
            'approvedAt' => null,
            'rejectionReason' => null,
            'currentStageLabel' => null,
            'items' => [],
            'totalProposedBudget' => 0,
            'unallocatedBalance' => 0,
            'exists' => false,
        ];
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasPermission('annual_budgets.view'), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasPermission('annual_budgets.manage'), 403);
    }
}
