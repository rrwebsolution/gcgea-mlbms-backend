<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\ContributionFund;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

class ContributionFundAllocator
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function allocate(Contribution $contribution, ?User $actor = null): void
    {
        $contribution->fundAllocations()->delete();

        if ($contribution->contribution_type !== 'Monthly Dues' || $contribution->status !== 'Posted' || ! $this->automaticAllocationEnabled()) {
            return;
        }

        $funds = ContributionFund::query()->where('is_enabled', true)->orderBy('display_order')->orderBy('id')->get();
        if ($funds->isEmpty()) {
            throw new DomainException('No enabled contribution funds are configured.');
        }

        $amounts = $this->calculate($funds, (float) $contribution->amount);
        foreach ($funds as $index => $fund) {
            $contribution->fundAllocations()->create([
                'fund_id' => $fund->id,
                'fund_name_snapshot' => $fund->fund_name,
                'allocated_amount' => $amounts[$index],
            ]);
        }
        if ($actor) {
            $this->auditLog->record($actor, $contribution, 'contribution_allocation_posted', count($amounts).' fund allocation(s) posted.');
        }
    }

    /** @return array<int, float> */
    public function calculate(Collection $funds, float $duesAmount): array
    {
        $amounts = $funds->map(fn (ContributionFund $fund) => round(
            $fund->allocation_type === 'Percentage'
                ? $duesAmount * ((float) $fund->allocation_value / 100)
                : (float) $fund->allocation_value,
            2
        ))->all();

        $allocated = round(array_sum($amounts), 2);
        if ($this->validationRequired() && abs($allocated - round($duesAmount, 2)) > 0.009) {
            throw new DomainException("Enabled fund allocations total {$allocated}; they must equal the Monthly Dues amount of {$duesAmount}.");
        }

        // Put any one-cent rounding difference into the last fund so the ledger
        // always reconciles exactly to the posted contribution.
        if ($amounts !== []) {
            $last = array_key_last($amounts);
            $amounts[$last] = round($amounts[$last] + ($duesAmount - $allocated), 2);
        }

        return $amounts;
    }

    private function contributionSettings(): array
    {
        return \App\Models\SystemSetting::query()->where('section', 'contribution')->value('value') ?? [];
    }

    private function automaticAllocationEnabled(): bool
    {
        return (bool) ($this->contributionSettings()['enableAutomaticFundAllocation'] ?? true);
    }

    private function validationRequired(): bool
    {
        return (bool) ($this->contributionSettings()['requireAllocationValidation'] ?? true);
    }
}
