<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Deduction;
use App\Models\DeductionType;
use App\Models\Loan;
use App\Models\PayrollDeductionHeader;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PayrollDeductionPostingService
{
    public function __construct(private readonly LoanPaymentPoster $loanPaymentPoster, private readonly AuditLogService $auditLog, private readonly ContributionFundAllocator $fundAllocator) {}

    public function post(PayrollDeductionHeader $payroll, User $actor): PayrollDeductionHeader
    {
        return DB::transaction(function () use ($payroll, $actor): PayrollDeductionHeader {
            $payroll = PayrollDeductionHeader::query()->with(['details', 'member.office'])->lockForUpdate()->findOrFail($payroll->id);
            if ($payroll->status !== 'Draft') {
                throw new DomainException('Only draft payroll deductions may be posted.');
            }

            $details = $payroll->details->keyBy('deduction_code');
            $monthlyDues = (float) $details['monthly_dues']->amount;
            $cashPabaon = (float) $details['cash_pabaon']->amount;
            $loanDeduction = (float) $details['loan']->amount;

            if ($monthlyDues > 0) {
                $this->assertNoContributionDuplicate($payroll);
                $contribution = Contribution::create([
                    'member_id' => $payroll->member_id, 'contribution_period' => $payroll->payroll_period, 'amount' => $monthlyDues,
                    'payment_date' => $payroll->payroll_date, 'payment_method' => 'Payroll Deduction', 'payroll_reference' => $payroll->payroll_reference,
                    'remarks' => $payroll->remarks, 'encoded_by' => $actor->full_name, 'status' => 'Posted',
                ]);
                $contribution->update(['reference_number' => app(DocumentNumberService::class)->generate('contribution', $contribution->id, $contribution->payment_date)]);
                $this->fundAllocator->allocate($contribution, $actor);
                $details['monthly_dues']->update(['contribution_id' => $contribution->id]);
                $this->auditLog->record($actor, $payroll, 'contribution_created');
            }

            if ($cashPabaon > 0) {
                $type = DeductionType::query()->where('code', 'pabaon')->where('is_active', true)->firstOrFail();
                if (Deduction::query()->where('member_id', $payroll->member_id)->where('deduction_type_id', $type->id)->where('period', $payroll->payroll_period)->where('status', 'Posted')->exists()) {
                    throw new DomainException('Cash Pabaon has already been posted for this member and payroll period.');
                }
                $deduction = Deduction::create(['member_id' => $payroll->member_id, 'deduction_type_id' => $type->id, 'period' => $payroll->payroll_period, 'amount' => $cashPabaon, 'payment_date' => $payroll->payroll_date, 'payroll_reference' => $payroll->payroll_reference, 'remarks' => $payroll->remarks, 'encoded_by' => $actor->full_name, 'status' => 'Posted']);
                $deduction->update(['reference_number' => 'GCGEA-DED-'.now()->year.'-'.str_pad((string) $deduction->id, 6, '0', STR_PAD_LEFT)]);
                $details['cash_pabaon']->update(['deduction_id' => $deduction->id]);
            }

            if ($loanDeduction > 0) {
                $loan = Loan::query()->where('member_id', $payroll->member_id)->whereIn('status', ['Released', 'Active', 'Overdue', 'Restructured'])->latest()->lockForUpdate()->first();
                if (! $loan) {
                    throw new DomainException('No active Cash Assistance Loan is available.');
                }
                if ($loan->payments()->where('payroll_reference', $payroll->payroll_reference)->where('status', 'Posted')->exists()) {
                    throw new DomainException('A loan payment already exists for this payroll reference.');
                }
                $result = $this->loanPaymentPoster->post($loan, ['amountPaid' => $loanDeduction], ['paymentDate' => $payroll->payroll_date->toDateString(), 'paymentMethod' => 'Payroll Deduction', 'officialReceiptNumber' => $payroll->payroll_reference, 'receivedBy' => $actor->full_name, 'payrollReference' => $payroll->payroll_reference, 'remarks' => $payroll->remarks]);
                $details['loan']->update(['loan_id' => $loan->id, 'loan_payment_id' => $result->payment->id]);
                $this->auditLog->record($actor, $payroll, 'loan_payment_created');
                if ($result->statusAfter === 'Fully Paid') {
                    $this->auditLog->record($actor, $payroll, 'loan_fully_paid');
                }
            }

            $payroll->update(['status' => 'Posted', 'posted_by_user_id' => $actor->id, 'posted_at' => now()]);
            $this->auditLog->record($actor, $payroll, 'payroll_posted');

            return $payroll->fresh(['member.office', 'details', 'postedBy']);
        }, 3);
    }

    private function assertNoContributionDuplicate(PayrollDeductionHeader $payroll): void
    {
        if (Contribution::query()->where('member_id', $payroll->member_id)->where('contribution_period', $payroll->payroll_period)->where('status', 'Posted')->exists()) {
            throw new DomainException('Monthly dues have already been posted for this member and payroll period.');
        }
    }
}
