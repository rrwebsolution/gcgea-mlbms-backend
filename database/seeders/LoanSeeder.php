<?php

namespace Database\Seeders;

use App\Models\Loan;
use App\Models\LoanType;
use App\Models\Member;
use App\Services\LoanCalculator;
use App\Services\LoanEligibilityService;
use Illuminate\Database\Seeder;

/**
 * Generates a representative spread of loans (not a literal transcription of
 * src/services/mock-data/loans.ts) across a handful of seeded members, loan
 * types, and statuses — including statuses the create API itself can't
 * produce yet (Active/Released/Overdue/Fully Paid), since dashboard/report/
 * eligibility logic needs a realistic mix to be meaningful.
 */
class LoanSeeder extends Seeder
{
    public function run(): void
    {
        $calculator = app(LoanCalculator::class);
        $eligibility = app(LoanEligibilityService::class);

        $members = Member::where('membership_status', 'Active')->where('is_archived', false)->orderBy('id')->get();
        $loanTypes = LoanType::where('status', 'Active')->get();

        if ($members->isEmpty() || $loanTypes->isEmpty()) {
            return;
        }

        Loan::query()->delete();

        // [memberOffset, loanTypeOffset, monthsAgoApplied, termMonths, status]
        $plans = [
            [0, 0, 8, 12, 'Fully Paid'],
            [1, 1, 5, 6, 'Active'],
            [2, 0, 10, 24, 'Active'],
            [3, 2, 14, 18, 'Overdue'],
            [4, 1, 2, 12, 'Released'],
            [5, 0, 1, 12, 'Approved'],
            [6, 3, 0, 12, 'Submitted'],
            [7, 0, 0, 6, 'Draft'],
            [8, 2, 6, 24, 'Active'],
            [9, 1, 3, 6, 'Rejected'],
            [10, 0, 9, 12, 'Fully Paid'],
            [11, 3, 1, 6, 'Submitted'],
        ];

        $counter = 1;

        foreach ($plans as [$memberOffset, $loanTypeOffset, $monthsAgo, $termMonths, $status]) {
            $member = $members->get($memberOffset % $members->count());
            $loanType = $loanTypes->get($loanTypeOffset % $loanTypes->count());
            if (! $member || ! $loanType) {
                continue;
            }

            $requestedAmount = min($loanType->max_amount, max($loanType->min_amount, 20000));
            $applicationDate = now()->subMonths($monthsAgo);
            $firstDueDate = $applicationDate->copy()->addMonth();

            $computation = $calculator->compute(
                (float) $requestedAmount,
                (float) $loanType->default_interest_rate,
                $termMonths,
                (float) $loanType->processing_fee,
                $loanType->interest_method,
                $firstDueDate,
            );

            $eligibilityResult = $eligibility->evaluate($member, $loanType, (float) $requestedAmount, $termMonths);

            $isReleasedOrBeyond = in_array($status, ['Active', 'Overdue', 'Released', 'Fully Paid'], true);
            $paidInstallments = match ($status) {
                'Fully Paid' => $termMonths,
                'Active', 'Overdue' => (int) floor($termMonths / 2),
                default => 0,
            };

            $outstandingBalance = $isReleasedOrBeyond
                ? array_sum(array_column(array_slice($computation['schedule'], $paidInstallments), 'amount_due'))
                : ($status === 'Draft' ? 0 : $computation['totalAmountPayable']);

            $loan = Loan::create([
                'application_date' => $applicationDate,
                'member_id' => $member->id,
                'loan_type_id' => $loanType->id,
                'requested_amount' => $requestedAmount,
                'approved_amount' => $status === 'Rejected' || $status === 'Draft' || $status === 'Submitted' ? null : $requestedAmount,
                'term_months' => $termMonths,
                'interest_rate' => $loanType->default_interest_rate,
                'processing_fee' => $loanType->processing_fee,
                'purpose' => 'Personal financial assistance',
                'payment_method' => 'Payroll Deduction',
                'first_due_date' => $firstDueDate,
                'maturity_date' => $computation['maturityDate'],
                'principal' => $computation['principal'],
                'total_interest' => $computation['totalInterest'],
                'net_proceeds' => $computation['netProceeds'],
                'total_amount_payable' => $computation['totalAmountPayable'],
                'monthly_amortization' => $computation['monthlyAmortization'],
                'outstanding_balance' => round($outstandingBalance, 2),
                'status' => $status,
                'assigned_officer' => 'Erwin S. Cabahug',
                'eligibility' => $eligibilityResult,
                'requirements' => [
                    ['label' => 'Loan Application Form', 'completed' => true],
                    ['label' => 'Valid ID', 'completed' => true],
                ],
                'release_date' => $isReleasedOrBeyond ? $applicationDate->copy()->addDays(3)->toDateString() : null,
                'release_reference_number' => $isReleasedOrBeyond ? "GCGEA-REL-{$counter}" : null,
                'release_method' => $isReleasedOrBeyond ? 'Payroll Deduction' : null,
                'actual_released_amount' => $isReleasedOrBeyond ? $computation['netProceeds'] : null,
                'rejection_reason' => $status === 'Rejected' ? 'Insufficient contribution history at time of application.' : null,
                'created_by' => 'Erwin S. Cabahug',
            ]);
            $loan->update(['application_number' => 'GCGEA-LN-'.$applicationDate->year.'-'.str_pad((string) $loan->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($computation['schedule'] as $i => $entry) {
                $installmentNumber = $i + 1;
                $entryStatus = 'Upcoming';
                $amountPaid = 0;
                if ($isReleasedOrBeyond && $installmentNumber <= $paidInstallments) {
                    $entryStatus = 'Paid';
                    $amountPaid = $entry['amount_due'];
                } elseif ($status === 'Overdue' && $installmentNumber === $paidInstallments + 1) {
                    $entryStatus = 'Overdue';
                }
                $loan->schedule()->create([...$entry, 'status' => $entryStatus, 'amount_paid' => $amountPaid]);
            }

            $loan->approvalHistory()->create([
                'action' => 'Draft Created',
                'performed_by' => 'Erwin S. Cabahug',
                'performed_at' => $applicationDate,
            ]);
            if ($status !== 'Draft') {
                $loan->approvalHistory()->create([
                    'action' => 'Submitted',
                    'performed_by' => 'Erwin S. Cabahug',
                    'performed_at' => $applicationDate->copy()->addHour(),
                ]);
            }
            if ($isReleasedOrBeyond || $status === 'Approved') {
                $loan->approvalHistory()->create([
                    'action' => 'Approved',
                    'performed_by' => 'Reynaldo C. Mag-abo',
                    'performed_at' => $applicationDate->copy()->addDay(),
                ]);
            }
            if ($isReleasedOrBeyond) {
                $loan->approvalHistory()->create([
                    'action' => 'Released',
                    'performed_by' => 'Erwin S. Cabahug',
                    'performed_at' => $applicationDate->copy()->addDays(3),
                ]);
            }
            if ($status === 'Rejected') {
                $loan->approvalHistory()->create([
                    'action' => 'Rejected',
                    'performed_by' => 'Reynaldo C. Mag-abo',
                    'performed_at' => $applicationDate->copy()->addDay(),
                    'remarks' => $loan->rejection_reason,
                ]);
            }

            $counter++;
        }
    }
}
