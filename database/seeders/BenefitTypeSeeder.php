<?php

namespace Database\Seeders;

use App\Models\BenefitType;
use Illuminate\Database\Seeder;

/**
 * Mirrors src/services/mock-data/benefit-types.ts — keep the two in sync if
 * the catalog of configured benefit programs changes.
 */
class BenefitTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $benefitTypes = [
            [
                'name' => 'Medical Assistance',
                'description' => 'Financial aid for hospitalization or outpatient medical expenses.',
                'default_amount' => 5000,
                'maximum_amount' => 15000,
                'eligibility_requirements' => 'Active member for at least 6 months',
                'required_membership_months' => 6,
                'frequency_limit' => 'Twice per year',
                'required_documents' => ['Medical Certificate', 'Hospital Bill / Receipts', 'Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Hospital Assistance',
                'description' => 'Assistance for confinement-related hospital expenses.',
                'default_amount' => 8000,
                'maximum_amount' => 20000,
                'eligibility_requirements' => 'Active member, at least 6 months tenure',
                'required_membership_months' => 6,
                'frequency_limit' => 'Twice per year',
                'required_documents' => ['Hospital Bill', 'Discharge Summary', 'Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Burial Assistance',
                'description' => 'Assistance to the family of a deceased member.',
                'default_amount' => 20000,
                'maximum_amount' => 20000,
                'eligibility_requirements' => 'Member in good standing at time of death',
                'required_membership_months' => 0,
                'frequency_limit' => 'Once',
                'required_documents' => ['Death Certificate', 'Funeral Contract / Receipt', 'Beneficiary Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Death Benefit',
                'description' => 'Lump sum benefit released to legal beneficiaries upon death of a member.',
                'default_amount' => 50000,
                'maximum_amount' => 50000,
                'eligibility_requirements' => 'Member in good standing at time of death',
                'required_membership_months' => 12,
                'frequency_limit' => 'Once',
                'required_documents' => ['Death Certificate', 'Beneficiary Designation Form', 'Beneficiary Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Emergency Assistance',
                'description' => 'Assistance for urgent, unforeseen financial needs.',
                'default_amount' => 3000,
                'maximum_amount' => 10000,
                'eligibility_requirements' => 'Active member',
                'required_membership_months' => 3,
                'frequency_limit' => 'Once per year',
                'required_documents' => ['Incident Report / Affidavit', 'Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Calamity Assistance',
                'description' => 'Assistance for members affected by natural or man-made calamities.',
                'default_amount' => 5000,
                'maximum_amount' => 10000,
                'eligibility_requirements' => 'Active member residing in affected area',
                'required_membership_months' => 0,
                'frequency_limit' => 'Per declared calamity',
                'required_documents' => ['Barangay Certification', 'Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Retirement Benefit',
                'description' => 'One-time benefit released upon retirement from government service.',
                'default_amount' => 30000,
                'maximum_amount' => 30000,
                'eligibility_requirements' => 'Retiring member with at least 10 years of membership',
                'required_membership_months' => 120,
                'frequency_limit' => 'Once',
                'required_documents' => ['Retirement Order', 'Service Record', 'Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Cash Pabaon',
                'description' => 'Send-off cash gift for retiring members.',
                'default_amount' => 10000,
                'maximum_amount' => 10000,
                'eligibility_requirements' => 'Retiring member in good standing',
                'required_membership_months' => 60,
                'frequency_limit' => 'Once',
                'required_documents' => ['Retirement Order', 'Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Solidarity Assistance',
                'description' => 'Mutual aid benefit funded through the solidarity assistance program.',
                'default_amount' => 15000,
                'maximum_amount' => 15000,
                'eligibility_requirements' => 'Active solidarity fund contributor',
                'required_membership_months' => 12,
                'frequency_limit' => 'Once',
                'required_documents' => ['Solidarity Fund Enrollment Form', 'Supporting Documents', 'Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Other Financial Assistance',
                'description' => 'Catch-all benefit type for special cases approved by the board.',
                'default_amount' => 2000,
                'maximum_amount' => 10000,
                'eligibility_requirements' => 'Subject to board discretion',
                'required_membership_months' => 0,
                'frequency_limit' => 'Case-to-case',
                'required_documents' => ['Board Resolution / Approval', 'Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
        ];

        foreach ($benefitTypes as $benefitType) {
            BenefitType::updateOrCreate(['name' => $benefitType['name']], $benefitType);
        }

        $this->seedResolution242026CoreBenefits();
        $this->seedResolution242026CashPabaonProgram();
    }

    /**
     * GCGEA Board Resolution No. 24-2026, Table 1 — Core Benefits of the
     * Association, prorated by months of paid monthly-dues contributions.
     * Distinct from the pre-existing flat "Retirement Benefit" mock entry —
     * named to match the resolution exactly so the two don't collide.
     */
    private function seedResolution242026CoreBenefits(): void
    {
        $tiers = [
            [12, 23, 35], [24, 35, 45], [36, 47, 55], [48, 59, 65],
            [60, 71, 75], [72, 83, 85], [84, 95, 95], [96, null, 100],
        ];

        $coreBenefits = [
            [
                'name' => 'Retirement and Separation Benefit',
                'description' => 'Prorated by months of paid monthly dues (Resolution No. 24-2026, Table 1).',
                'default_amount' => 10000,
                'maximum_amount' => 10000,
                'eligibility_requirements' => 'Member in good standing at retirement or separation',
                'required_membership_months' => 12,
                'frequency_limit' => 'Once',
                'required_documents' => ['Retirement/Separation Order', 'Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Mortuary Cash Assistance',
                'description' => 'Prorated by months of paid monthly dues (Resolution No. 24-2026, Table 1).',
                'default_amount' => 10000,
                'maximum_amount' => 10000,
                'eligibility_requirements' => 'Member in good standing at time of death',
                'required_membership_months' => 12,
                'frequency_limit' => 'Once',
                'required_documents' => ['Death Certificate', 'Beneficiary Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
            [
                'name' => 'Mortuary Cash Assistance for Nuclear Family Member',
                'description' => 'Prorated by months of paid monthly dues (Resolution No. 24-2026, Table 1). For an unmarried member, this instead pays the fixed per-sibling schedule (up to 3 siblings: ₱15,000 / ₱10,000 / ₱5,000).',
                'default_amount' => 5000,
                'maximum_amount' => 5000,
                'eligibility_requirements' => 'Member in good standing; claim on behalf of a qualified nuclear family member',
                'required_membership_months' => 12,
                'frequency_limit' => 'Once',
                'required_documents' => ['Death Certificate', 'Proof of Relationship', 'Beneficiary Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ],
        ];

        foreach ($coreBenefits as $definition) {
            $benefitType = BenefitType::updateOrCreate(
                ['name' => $definition['name']],
                ['proration_basis' => 'dues', ...$definition]
            );

            $benefitType->prorationTiers()->delete();
            foreach ($tiers as [$min, $max, $pct]) {
                $benefitType->prorationTiers()->create(['min_months' => $min, 'max_months' => $max, 'percentage' => $pct]);
            }
        }
    }

    /**
     * GCGEA Board Resolution No. 24-2026, Table 2 — Cash Pabaon Program, a
     * lump-sum claim prorated by months of paid Pabaon deductions, whose
     * 100%-tier base escalates by fiscal year. Distinct from both the
     * existing flat "Cash Pabaon" mock entry and the recurring ₱200/month
     * payroll Pabaon deduction (a different concept entirely).
     */
    private function seedResolution242026CashPabaonProgram(): void
    {
        $tiers = [
            [12, 35, 12], [36, 47, 15], [48, 59, 20], [60, 71, 35],
            [72, 83, 40], [84, 95, 45], [96, 107, 50], [108, 119, 55],
            [120, 131, 65], [132, 143, 70], [144, 155, 75], [156, 167, 80],
            [168, 179, 90], [180, null, 100],
        ];

        $benefitType = BenefitType::updateOrCreate(
            ['name' => 'Cash Pabaon Program'],
            [
                'description' => 'Prorated by months of paid Pabaon deductions; 100% base escalates by fiscal year (Resolution No. 24-2026, Table 2).',
                'default_amount' => 70000,
                'maximum_amount' => 100000,
                'proration_basis' => 'pabaon',
                'eligibility_requirements' => 'Member in good standing upon retirement/separation, or qualified nuclear family upon member\'s death',
                'required_membership_months' => 12,
                'frequency_limit' => 'Once',
                'required_documents' => ['Retirement/Separation Order or Death Certificate', 'Valid ID'],
                'approval_required' => true,
                'status' => 'Active',
            ]
        );

        $benefitType->prorationTiers()->delete();
        foreach ($tiers as [$min, $max, $pct]) {
            $benefitType->prorationTiers()->create(['min_months' => $min, 'max_months' => $max, 'percentage' => $pct]);
        }

        $benefitType->fyAmounts()->delete();
        $benefitType->fyAmounts()->createMany([
            ['fiscal_year' => 2026, 'base_amount' => 70000],
            ['fiscal_year' => 2027, 'base_amount' => 80000],
            ['fiscal_year' => 2028, 'base_amount' => 90000],
            ['fiscal_year' => null, 'base_amount' => 100000],
        ]);
    }
}
