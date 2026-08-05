<?php

namespace App\Http\Controllers;

use App\Models\ContributionFund;
use App\Models\ContributionFundAllocation;
use App\Services\AuditLogService;
use App\Services\FundLedgerService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContributionFundController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index()
    {
        return response()->json(ContributionFund::query()->orderBy('display_order')->orderBy('id')->get()->map(fn ($fund) => $this->resource($fund)));
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);
        $fund = ContributionFund::create($this->validated($request));
        $this->auditLog->record($request->user(), $fund, 'fund_created');

        return response()->json($this->resource($fund), 201);
    }

    public function update(Request $request, ContributionFund $contributionFund)
    {
        $this->authorizeManage($request);
        $contributionFund->update($this->validated($request, $contributionFund));
        $this->auditLog->record($request->user(), $contributionFund, 'fund_updated', 'Contribution fund configuration changed.');

        return response()->json($this->resource($contributionFund->fresh()));
    }

    public function destroy(Request $request, ContributionFund $contributionFund)
    {
        $this->authorizeManage($request);
        if ($contributionFund->allocations()->exists()) {
            return response()->json(['message' => 'Funds with posted allocations cannot be deleted. Disable the fund instead.'], 422);
        }
        $this->auditLog->record($request->user(), $contributionFund, 'fund_deleted');
        $contributionFund->delete();

        return response()->noContent();
    }

    public function report(Request $request)
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $from = $request->date('dateFrom')?->startOfDay();
        $to = $request->date('dateTo')?->endOfDay();
        $rows = ContributionFund::query()->orderBy('display_order')->get()->map(function ($fund) use ($from, $to) {
            $base = ContributionFundAllocation::query()->where('fund_id', $fund->id)
                ->whereHas('contribution', fn ($q) => $q->where('status', 'Posted'));
            if ($from) {
                $base->whereHas('contribution', fn ($q) => $q->where('payment_date', '>=', $from));
            }
            if ($to) {
                $base->whereHas('contribution', fn ($q) => $q->where('payment_date', '<=', $to));
            }

            return [
                'fundId' => (string) $fund->id,
                'fundName' => $fund->fund_name,
                'allocatedAmount' => app(FundLedgerService::class)->credits($fund),
                'disbursedAmount' => (float) $fund->transactions()->where('transaction_type', 'Debit')->sum('amount'),
                'currentBalance' => app(FundLedgerService::class)->balance($fund),
                'monthlyTotal' => (float) (clone $base)->whereHas('contribution', fn ($q) => $q->where('contribution_period', now()->format('Y-m')))->sum('allocated_amount'),
                'annualTotal' => (float) (clone $base)->whereHas('contribution', fn ($q) => $q->whereYear('payment_date', now()->year))->sum('allocated_amount'),
            ];
        });

        return response()->json($rows);
    }

    private function validated(Request $request, ?ContributionFund $fund = null): array
    {
        return $request->validate([
            'fund_name' => ['required', 'string', 'max:255', Rule::unique('contribution_funds', 'fund_name')->ignore($fund?->id)],
            'allocation_type' => ['required', Rule::in(['Percentage', 'Fixed Amount'])],
            'allocation_value' => ['required', 'numeric', 'min:0', $request->input('allocation_type') === 'Percentage' ? 'max:100' : 'max:9999999999.99'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_enabled' => ['required', 'boolean'],
            'display_order' => ['required', 'integer', 'min:0'],
        ]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasPermission('settings.contribution') || $request->user()->hasPermission('settings.update'), 403);
    }

    private function resource(ContributionFund $fund): array
    {
        return [
            'id' => (string) $fund->id,
            'fundName' => $fund->fund_name,
            'allocationType' => $fund->allocation_type,
            'allocationValue' => (float) $fund->allocation_value,
            'description' => $fund->description,
            'status' => $fund->is_enabled ? 'Enabled' : 'Disabled',
            'displayOrder' => $fund->display_order,
        ];
    }
}
