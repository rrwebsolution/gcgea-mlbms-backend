<?php

namespace App\Http\Controllers;

use App\Http\Resources\DisbursementResource;
use App\Models\AnnualBudget;
use App\Models\AnnualBudgetItem;
use App\Models\Disbursement;
use App\Services\ApprovalWorkflowService;
use App\Services\AuditLogService;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DisbursementController extends Controller
{
    private const WITH = ['annualBudget', 'budgetItem', 'approvalInstance.currentStage'];

    public function __construct(
        private readonly ApprovalWorkflowService $workflow,
        private readonly AuditLogService $auditLog,
    ) {}

    public function index(Request $request)
    {
        $this->authorizePermission($request, 'disbursements.view');
        $query = Disbursement::query()->with(self::WITH)->orderByDesc('disbursement_date')->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('year')) {
            $query->whereYear('disbursement_date', $request->integer('year'));
        }
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->trim()->toString().'%';
            $query->where(fn ($q) => $q->where('reference_number', 'ilike', $search)
                ->orWhere('payee', 'ilike', $search)
                ->orWhereHas('budgetItem', fn ($item) => $item->where('account_title', 'ilike', $search)));
        }

        return response()->json(ApiPagination::make(
            $query->paginate(max(1, min(100, $request->integer('perPage', 10)))),
            DisbursementResource::class
        ));
    }

    /** Full, unpaginated list — backs the Disbursements page's client-side search/filter/pagination. */
    public function all(Request $request)
    {
        $this->authorizePermission($request, 'disbursements.view');

        return DisbursementResource::collection(
            Disbursement::query()->with(self::WITH)->orderByDesc('disbursement_date')->orderByDesc('id')->get()
        );
    }

    public function show(Request $request, Disbursement $disbursement)
    {
        $this->authorizePermission($request, 'disbursements.view');

        return new DisbursementResource($disbursement->load(self::WITH));
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, 'disbursements.manage');
        $data = $this->validated($request);
        $disbursement = DB::transaction(function () use ($request, $data) {
            $this->validateBudgetAvailability($data);
            $year = (int) substr($data['disbursementDate'], 0, 4);
            $next = (int) Disbursement::query()->whereYear('disbursement_date', $year)->count() + 1;

            return Disbursement::create([
                ...$this->columns($data),
                'reference_number' => sprintf('DISB-%d-%05d', $year, $next),
                'status' => 'Draft',
                'prepared_by' => $request->user()->full_name,
            ]);
        });
        $this->auditLog->record($request->user(), $disbursement, 'disbursement_created');

        return (new DisbursementResource($disbursement->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Disbursement $disbursement)
    {
        $this->authorizePermission($request, 'disbursements.manage');
        abort_unless(in_array($disbursement->status, ['Draft', 'Rejected'], true), 422, 'Only Draft or Rejected disbursements can be edited.');
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $disbursement) {
            $this->validateBudgetAvailability($data, $disbursement);
            $disbursement->update([
                ...$this->columns($data),
                'status' => 'Draft',
                'rejection_reason' => null,
            ]);
        });
        $this->auditLog->record($request->user(), $disbursement, 'disbursement_updated');

        return new DisbursementResource($disbursement->fresh(self::WITH));
    }

    public function submit(Request $request, Disbursement $disbursement)
    {
        $this->authorizePermission($request, 'disbursements.submit');
        abort_unless(in_array($disbursement->status, ['Draft', 'Rejected'], true), 422, 'Only Draft or Rejected disbursements can be submitted.');
        $this->validateBudgetAvailability([
            'annualBudgetId' => $disbursement->annual_budget_id,
            'annualBudgetItemId' => $disbursement->annual_budget_item_id,
            'amount' => $disbursement->amount,
        ], $disbursement);
        $this->workflow->startInstance($disbursement, 'disbursement', $request->user());

        return new DisbursementResource($disbursement->fresh(self::WITH));
    }

    public function markPaid(Request $request, Disbursement $disbursement)
    {
        $this->authorizePermission($request, 'disbursements.pay');
        abort_unless($disbursement->status === 'Approved', 422, 'Only approved disbursements can be marked as paid.');
        $data = $request->validate([
            'paymentMethod' => ['required', Rule::in(['Cash', 'Bank Transfer', 'Check'])],
            'paymentReference' => ['nullable', 'string', 'max:255'],
        ]);
        $disbursement->update([
            'status' => 'Paid',
            'payment_method' => $data['paymentMethod'],
            'payment_reference' => $data['paymentReference'] ?? null,
            'paid_by' => $request->user()->full_name,
            'paid_at' => now(),
        ]);
        $this->auditLog->record($request->user(), $disbursement, 'disbursement_paid');

        return new DisbursementResource($disbursement->fresh(self::WITH));
    }

    public function void(Request $request, Disbursement $disbursement)
    {
        $this->authorizePermission($request, 'disbursements.void');
        abort_unless($disbursement->status === 'Paid', 422, 'Only paid disbursements can be voided.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $disbursement->update(['status' => 'Voided', 'void_reason' => $data['reason']]);
        $this->auditLog->record($request->user(), $disbursement, 'disbursement_voided', $data['reason']);

        return new DisbursementResource($disbursement->fresh(self::WITH));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'annualBudgetId' => ['required', 'integer', 'exists:annual_budgets,id'],
            'annualBudgetItemId' => ['required', 'integer', 'exists:annual_budget_items,id'],
            'disbursementDate' => ['required', 'date'],
            'payee' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'paymentMethod' => ['required', Rule::in(['Cash', 'Bank Transfer', 'Check'])],
            'paymentReference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function columns(array $data): array
    {
        return [
            'annual_budget_id' => $data['annualBudgetId'],
            'annual_budget_item_id' => $data['annualBudgetItemId'],
            'disbursement_date' => $data['disbursementDate'],
            'payee' => trim($data['payee']),
            'amount' => $data['amount'],
            'payment_method' => $data['paymentMethod'],
            'payment_reference' => $data['paymentReference'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    private function validateBudgetAvailability(array $data, ?Disbursement $current = null): void
    {
        $budget = AnnualBudget::findOrFail($data['annualBudgetId']);
        abort_unless($budget->status === 'Approved', 422, 'Select an approved annual budget.');
        $item = AnnualBudgetItem::query()
            ->where('id', $data['annualBudgetItemId'])
            ->where('annual_budget_id', $budget->id)
            ->firstOrFail();
        $committed = Disbursement::query()
            ->where('annual_budget_item_id', $item->id)
            ->whereNotIn('status', ['Rejected', 'Voided'])
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->sum('amount');
        abort_if((float) $committed + (float) $data['amount'] > (float) $item->proposed_amount, 422, 'This disbursement exceeds the remaining budget for the selected account.');
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }
}
