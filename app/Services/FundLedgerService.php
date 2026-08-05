<?php

namespace App\Services;

use App\Models\BenefitApplication;
use App\Models\Contribution;
use App\Models\ContributionFund;
use App\Models\FundTransaction;
use App\Models\Loan;
use App\Models\User;
use DomainException;

class FundLedgerService
{
    public function recordLoanRelease(Loan $loan, float $amount, User $actor): FundTransaction
    {
        return $this->recordDebit('Loan Investment Fund', $amount, $loan, $loan->application_number, "Loan released to {$loan->member->full_name}", $actor);
    }

    public function recordBenefitRelease(BenefitApplication $benefit, float $amount, User $actor): FundTransaction
    {
        $name = strtolower($benefit->benefitType->name);
        $fundName = match (true) {
            str_contains($name, 'cash pabaon') => 'Cash Pabaon Fund',
            str_contains($name, 'mortuary') => 'Mortuary Fund',
            str_contains($name, 'retirement'), str_contains($name, 'separation') => 'Retirement Fund',
            str_contains($name, 'emergency') => 'Emergency Fund',
            str_contains($name, 'operational') => 'Operational Fund',
            default => throw new DomainException("No contribution fund is mapped to {$benefit->benefitType->name}."),
        };

        return $this->recordDebit($fundName, $amount, $benefit, $benefit->application_number, "Benefit released to {$benefit->member->full_name}", $actor);
    }

    public function balance(ContributionFund $fund): float
    {
        $credits = $this->credits($fund);
        $debits = (float) FundTransaction::query()->where('fund_id', $fund->id)->where('transaction_type', 'Debit')->sum('amount');

        return round($credits - $debits, 2);
    }

    public function credits(ContributionFund $fund): float
    {
        return $fund->fund_name === 'Cash Pabaon Fund'
            ? (float) Contribution::query()->where('contribution_type', 'Cash Pabaon')->where('status', 'Posted')->sum('amount')
            : (float) $fund->allocations()->whereHas('contribution', fn ($query) => $query->where('status', 'Posted'))->sum('allocated_amount');
    }

    private function recordDebit(string $fundName, float $amount, Loan|BenefitApplication $source, ?string $reference, string $description, User $actor): FundTransaction
    {
        $fund = ContributionFund::query()->where('fund_name', $fundName)->lockForUpdate()->first();
        if (! $fund) {
            throw new DomainException("Required fund '{$fundName}' is not configured.");
        }

        return FundTransaction::query()->firstOrCreate(
            ['source_type' => $source::class, 'source_id' => $source->id, 'transaction_type' => 'Debit'],
            ['fund_id' => $fund->id, 'fund_name_snapshot' => $fund->fund_name, 'amount' => round($amount, 2), 'reference_number' => $reference, 'description' => $description, 'created_by_user_id' => $actor->id]
        );
    }
}
