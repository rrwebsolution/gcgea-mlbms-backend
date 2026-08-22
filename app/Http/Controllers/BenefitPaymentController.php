<?php

namespace App\Http\Controllers;

use App\Exceptions\BenefitPaymentPostingException;
use App\Http\Resources\BenefitPaymentResource;
use App\Models\BenefitApplication;
use App\Services\BenefitPaymentPoster;
use App\Services\FundLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BenefitPaymentController extends Controller
{
    public function __construct(private readonly BenefitPaymentPoster $poster) {}

    public function store(Request $request, FundLedgerService $fundLedger)
    {
        if (! $request->user()->hasPermission('benefits.release')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $data = $request->validate([
            'memberId' => ['required', 'exists:members,id'],
            'benefitApplicationId' => ['required', 'exists:benefit_applications,id'],
            'paymentDate' => ['required', 'date'],
            'amountPaid' => ['required', 'numeric', 'gt:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        try {
            $payment = DB::transaction(function () use ($request, $data, $fundLedger) {
                $benefit = BenefitApplication::lockForUpdate()->findOrFail($data['benefitApplicationId']);

                if ((string) $benefit->member_id !== (string) $data['memberId']) {
                    abort(422, 'The selected benefit does not belong to this member.');
                }

                $payment = $this->poster->post($benefit, [
                    'amountPaid' => $data['amountPaid'],
                ], [
                    'paymentDate' => $data['paymentDate'],
                    'receivedBy' => $request->user()->full_name,
                    'remarks' => $data['remarks'] ?? null,
                ]);

                $fundLedger->recordBenefitFollowUpPayment($payment, $request->user());

                return $payment;
            });
        } catch (BenefitPaymentPostingException $e) {
            abort(422, $e->getMessage());
        }

        return new BenefitPaymentResource($payment->load(['member', 'benefitApplication']));
    }
}
