<?php

namespace Database\Seeders;

use App\Models\LoanType;
use Illuminate\Database\Seeder;

/**
 * Sole default loan product: GCGEA Board Resolution No. 24-2026's Solidarity
 * Cash Assistance Loan. Add new default loan types here if the Association
 * formally adopts more.
 */
class LoanTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedResolution242026SolidarityCashAssistanceLoan();
    }

    /**
     * GCGEA Board Resolution No. 24-2026, Table 3 — Solidarity Cash
     * Assistance Loan. Loanable amount is tiered by the member's monthly net
     * take-home pay rather than a single flat min/max.
     */
    private function seedResolution242026SolidarityCashAssistanceLoan(): void
    {
        $loanType = LoanType::updateOrCreate(
            ['name' => 'Solidarity Cash Assistance Loan'],
            [
                'description' => 'Loanable amount tiered by monthly net take-home pay (Resolution No. 24-2026, Table 3).',
                'min_amount' => 20000,
                'max_amount' => 50000,
                'default_interest_rate' => 1,
                'interest_method' => 'Flat Interest',
                'processing_fee' => 0,
                'service_charge_percent' => 1,
                'max_term_months' => 36,
                'required_membership_months' => 0,
                'required_contribution_months' => 0,
                'allow_existing_active_loan' => false,
                'status' => 'Active',
            ]
        );

        $loanType->incomeBrackets()->delete();
        $loanType->incomeBrackets()->createMany([
            ['min_net_pay' => 5000, 'max_net_pay' => 7000, 'loanable_amount' => 20000],
            ['min_net_pay' => 8000, 'max_net_pay' => 10000, 'loanable_amount' => 30000],
            ['min_net_pay' => 11000, 'max_net_pay' => 12000, 'loanable_amount' => 40000],
            ['min_net_pay' => 13000, 'max_net_pay' => null, 'loanable_amount' => 50000],
        ]);
    }
}
