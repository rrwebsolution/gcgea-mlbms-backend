<?php

namespace Database\Seeders;

use App\Models\BenefitType;
use Illuminate\Database\Seeder;

/**
 * Default benefit programs: GCGEA Board Resolution No. 24-2026's Core
 * Benefits (Table 1) and Cash Pabaon Program (Table 2), plus the Inactive
 * Emergency/Operational Fund reference entries. Add new default benefit
 * types here if the Association formally adopts more.
 */
class BenefitTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedResolution242026CoreBenefits();
        $this->seedResolution242026CashPabaonProgram();
        $this->seedContributionFundReferenceEntries();
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
        $resolution27Tiers = [
            [12, 35, 12], [36, 47, 15], [48, 59, 20], [60, 71, 35],
            [72, 83, 40], [84, 95, 45], [96, 107, 50], [108, 119, 55],
            [120, 131, 65], [132, 143, 70], [144, 155, 75], [156, 167, 80],
            [168, 179, 90], [180, null, 100],
        ];
        $resolution24Tiers = [
            [12, 24, 25], [25, 60, 50], [61, 96, 75], [97, null, 100],
        ];

        $benefitType = BenefitType::updateOrCreate(
            ['name' => 'Cash Pabaon Program'],
            [
                'description' => 'Cash Pabaon proration: Resolution 24-2026 for members before September 1, 2026; Resolution 27-2026 for new members from September 1, 2026 onward.',
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
        foreach ($resolution24Tiers as [$min, $max, $pct]) {
            $benefitType->prorationTiers()->create(['membership_scope' => 'legacy', 'min_months' => $min, 'max_months' => $max, 'percentage' => $pct]);
        }
        foreach ($resolution27Tiers as [$min, $max, $pct]) {
            $benefitType->prorationTiers()->create(['membership_scope' => 'new', 'min_months' => $min, 'max_months' => $max, 'percentage' => $pct]);
        }

        $benefitType->fyAmounts()->delete();
        $benefitType->fyAmounts()->createMany([
            ['fiscal_year' => 2025, 'base_amount' => 60000],
            ['fiscal_year' => 2026, 'base_amount' => 70000],
            ['fiscal_year' => 2027, 'base_amount' => 80000],
            ['fiscal_year' => 2028, 'base_amount' => 90000],
            ['fiscal_year' => null, 'base_amount' => 100000],
        ]);
    }

    /**
     * Emergency Fund and Operational Fund are reserve pools built from the
     * per-contribution fund allocations (Contribution Settings) — not
     * benefits a member applies for. Kept in the Benefit Types catalog for
     * reference/reporting only, seeded Inactive so they never appear as
     * choices in the benefit application wizard.
     */
    private function seedContributionFundReferenceEntries(): void
    {
        $referenceFunds = [
            [
                'name' => 'Emergency Fund',
                'description' => 'Reserve fund built from the Emergency Fund contribution allocation (see Contribution Settings). Not a member-claimable benefit.',
                'eligibility_requirements' => 'Not applicable — funded internally from contribution allocations, not claimed by members.',
            ],
            [
                'name' => 'Operational Fund',
                'description' => 'Reserve fund built from the Operational Fund contribution allocation (see Contribution Settings). Not a member-claimable benefit.',
                'eligibility_requirements' => 'Not applicable — funded internally from contribution allocations, not claimed by members.',
            ],
        ];

        foreach ($referenceFunds as $definition) {
            BenefitType::updateOrCreate(
                ['name' => $definition['name']],
                [
                    'description' => $definition['description'],
                    'default_amount' => 0,
                    'maximum_amount' => 0,
                    'eligibility_requirements' => $definition['eligibility_requirements'],
                    'required_membership_months' => 0,
                    'frequency_limit' => 'N/A',
                    'required_documents' => [],
                    'approval_required' => false,
                    'status' => 'Inactive',
                ]
            );
        }
    }
}
