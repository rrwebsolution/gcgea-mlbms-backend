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
    }
}
