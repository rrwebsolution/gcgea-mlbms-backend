<?php

namespace App\Services;

use App\Exceptions\BenefitPaymentPostingException;
use App\Models\BenefitApplication;
use App\Models\BenefitPayment;

/**
 * Posts a follow-up payment against the remaining balance of an already-
 * released benefit. Kept separate from ApprovalWorkflowService::act('release', ...)
 * because that engine hard-blocks re-acting on a subject once its approval
 * instance leaves 'pending' — mirrors how LoanPaymentPoster posts repeat
 * loan payments outside the loan's own approval workflow.
 */
class BenefitPaymentPoster
{
    private const TOLERANCE = 0.01;

    /**
     * @param  array{amountPaid: float}  $data
     * @param  array{paymentDate: string, receivedBy: ?string, remarks?: ?string}  $meta
     */
    public function post(BenefitApplication $benefit, array $data, array $meta): BenefitPayment
    {
        if ($benefit->status !== 'Released') {
            throw new BenefitPaymentPostingException('Payments can only be posted to an already-released benefit.');
        }

        $claimedAmount = (float) ($benefit->approved_amount ?? $benefit->requested_amount);
        $paidSoFar = (float) ($benefit->actual_released_amount ?? 0);
        $remainingBalance = round($claimedAmount - $paidSoFar, 2);

        if ($remainingBalance <= self::TOLERANCE) {
            throw new BenefitPaymentPostingException('This benefit has already been fully paid.');
        }

        $amountPaid = round((float) $data['amountPaid'], 2);
        if ($amountPaid <= 0) {
            throw new BenefitPaymentPostingException('The payment amount must be greater than zero.');
        }
        if ($amountPaid > $remainingBalance + self::TOLERANCE) {
            throw new BenefitPaymentPostingException('The payment exceeds the remaining balance of this benefit.');
        }

        $payment = BenefitPayment::create([
            'benefit_application_id' => $benefit->id,
            'member_id' => $benefit->member_id,
            'payment_date' => $meta['paymentDate'],
            'amount_paid' => $amountPaid,
            'received_by' => $meta['receivedBy'] ?? null,
            'remarks' => $meta['remarks'] ?? null,
            'status' => 'Posted',
        ]);
        $payment->update([
            'payment_reference_number' => app(DocumentNumberService::class)->generate('benefitPayment', $payment->id, $payment->payment_date),
        ]);

        $benefit->update([
            'actual_released_amount' => round($paidSoFar + $amountPaid, 2),
        ]);

        return $payment;
    }
}
