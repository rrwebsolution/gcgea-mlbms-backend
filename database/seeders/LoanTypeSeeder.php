<?php

namespace Database\Seeders;

use App\Models\LoanType;
use Illuminate\Database\Seeder;

/**
 * Mirrors src/services/mock-data/loan-types.ts — keep the two in sync if the
 * catalog of configured loan products changes.
 */
class LoanTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $loanTypes = [
            [
                'name' => 'Salary Loan',
                'description' => 'General purpose loan available to all active members in good standing.',
                'min_amount' => 5000,
                'max_amount' => 100000,
                'default_interest_rate' => 1.5,
                'interest_method' => 'Diminishing Balance',
                'processing_fee' => 200,
                'max_term_months' => 24,
                'required_membership_months' => 6,
                'required_contribution_months' => 6,
                'allow_existing_active_loan' => false,
                'status' => 'Active',
            ],
            [
                'name' => 'Emergency Loan',
                'description' => 'Short-term loan for urgent financial needs.',
                'min_amount' => 2000,
                'max_amount' => 20000,
                'default_interest_rate' => 1.0,
                'interest_method' => 'Flat Interest',
                'processing_fee' => 100,
                'max_term_months' => 12,
                'required_membership_months' => 3,
                'required_contribution_months' => 3,
                'allow_existing_active_loan' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Solidarity Assistance Loan',
                'description' => 'Special loan facility supporting solidarity fund members with flexible terms.',
                'min_amount' => 5000,
                'max_amount' => 150000,
                'default_interest_rate' => 1.25,
                'interest_method' => 'Diminishing Balance',
                'processing_fee' => 250,
                'max_term_months' => 36,
                'required_membership_months' => 6,
                'required_contribution_months' => 6,
                'allow_existing_active_loan' => false,
                'status' => 'Active',
            ],
            [
                'name' => 'Educational Loan',
                'description' => 'Loan for tuition and school-related expenses of members or dependents.',
                'min_amount' => 5000,
                'max_amount' => 50000,
                'default_interest_rate' => 1.0,
                'interest_method' => 'Flat Interest',
                'processing_fee' => 150,
                'max_term_months' => 12,
                'required_membership_months' => 6,
                'required_contribution_months' => 6,
                'allow_existing_active_loan' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Multi-Purpose Loan',
                'description' => 'Zero-interest loan for members under special circumstances, subject to board approval.',
                'min_amount' => 1000,
                'max_amount' => 10000,
                'default_interest_rate' => 0,
                'interest_method' => 'Zero Interest',
                'processing_fee' => 0,
                'max_term_months' => 6,
                'required_membership_months' => 3,
                'required_contribution_months' => 3,
                'allow_existing_active_loan' => true,
                'status' => 'Inactive',
            ],
        ];

        foreach ($loanTypes as $loanType) {
            LoanType::updateOrCreate(['name' => $loanType['name']], $loanType);
        }

        $this->seedResolution242026SolidarityCashAssistanceLoan();
    }

    /**
     * GCGEA Board Resolution No. 24-2026, Table 3 — Solidarity Cash
     * Assistance Loan. Loanable amount is tiered by the member's monthly net
     * take-home pay rather than a single flat min/max — distinct from the
     * pre-existing "Solidarity Assistance Loan" mock entry (different name,
     * different numbers), named to match the resolution exactly.
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
