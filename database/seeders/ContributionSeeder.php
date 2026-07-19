<?php

namespace Database\Seeders;

use App\Models\Contribution;
use App\Models\Member;
use Illuminate\Database\Seeder;

/** Mirrors the generation logic in src/services/mock-data/contributions.ts. */
class ContributionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $periods = ['2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'];
        $encoders = ['Danilo T. Quiñones', 'Girlie B. Nacua'];

        $members = Member::where('membership_status', 'Active')->orderBy('id')->get();

        Contribution::query()->delete();
        $counter = 1;

        foreach ($periods as $periodIndex => $period) {
            foreach ($members as $idx => $member) {
                // Simulate a few members skipping a period (unpaid), matching the mock's pattern.
                if (($idx + $periodIndex) % 11 === 0) {
                    continue;
                }

                $amount = $member->retiree_status === 'Retired' ? 100 : 150;
                [$year, $month] = explode('-', $period);
                $paymentDate = sprintf('%04d-%02d-05', $year, $month);

                $contribution = Contribution::create([
                    'member_id' => $member->id,
                    'contribution_period' => $period,
                    'amount' => $amount,
                    'payment_date' => $paymentDate,
                    'payment_method' => 'Payroll Deduction',
                    'payroll_reference' => "PR-{$period}-{$member->employee_number}",
                    'encoded_by' => $encoders[$counter % count($encoders)],
                    'status' => 'Posted',
                ]);
                $contribution->update(['reference_number' => 'GCGEA-CON-'.$year.'-'.str_pad((string) $contribution->id, 6, '0', STR_PAD_LEFT)]);
                $counter++;
            }
        }

        // One voided contribution for the void-workflow demo.
        $sampleMember = $members->get(2) ?? $members->first();
        if ($sampleMember) {
            $voided = Contribution::create([
                'member_id' => $sampleMember->id,
                'contribution_period' => '2026-06',
                'amount' => 150,
                'payment_date' => '2026-06-05',
                'payment_method' => 'Cash',
                'encoded_by' => 'Danilo T. Quiñones',
                'status' => 'Voided',
                'void_reason' => 'Wrong member selected during encoding; reposted under correct account.',
                'voided_by' => 'Danilo T. Quiñones',
                'voided_at' => '2026-06-05',
            ]);
            $voided->update(['reference_number' => 'GCGEA-CON-2026-'.str_pad((string) $voided->id, 6, '0', STR_PAD_LEFT)]);
        }
    }
}
