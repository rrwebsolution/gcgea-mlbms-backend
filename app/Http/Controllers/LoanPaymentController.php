<?php

namespace App\Http\Controllers;

use App\Http\Resources\LoanPaymentResource;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoanPaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = LoanPayment::with(['member', 'loan']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('payment_reference_number', 'ilike', "%{$search}%")
                    ->orWhereHas('member', fn ($mq) => $mq->where('surname', 'ilike', "%{$search}%")
                        ->orWhere('first_name', 'ilike', "%{$search}%"))
                    ->orWhereHas('loan', fn ($lq) => $lq->where('application_number', 'ilike', "%{$search}%"));
            });
        }

        $query->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, LoanPaymentResource::class));
    }

    public function all()
    {
        return LoanPaymentResource::collection(
            LoanPayment::with(['member', 'loan'])->orderBy('payment_date', 'desc')->get()
        );
    }

    public function store(Request $request)
    {
        if (! $request->user()->hasPermission('loan_payments.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $data = $request->validate([
            'memberId' => ['required', 'exists:members,id'],
            'loanApplicationId' => ['required', 'exists:loans,id'],
            'paymentDate' => ['required', 'date'],
            'amountPaid' => ['required', 'numeric', 'gt:0'],
            'penalty' => ['nullable', 'numeric', 'min:0'],
            'paymentMethod' => ['required', 'string', 'in:Payroll Deduction,Cash,Bank Transfer,Check'],
            'officialReceiptNumber' => ['required', 'string', 'max:255'],
            'payrollReference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $payment = DB::transaction(function () use ($request, $data) {
            $loan = Loan::lockForUpdate()->findOrFail($data['loanApplicationId']);

            if ((string) $loan->member_id !== (string) $data['memberId']) {
                abort(422, 'The selected loan does not belong to this member.');
            }
            if (! in_array($loan->status, ['Released', 'Active', 'Overdue', 'Restructured'], true)) {
                abort(422, 'Payments can only be posted to an active or released loan.');
            }

            $amountPaid = round((float) $data['amountPaid'], 2);
            $penalty = round((float) ($data['penalty'] ?? 0), 2);
            $amountApplied = round($amountPaid - $penalty, 2);
            $outstandingBalance = round((float) $loan->outstanding_balance, 2);

            if ($amountApplied <= 0) {
                abort(422, 'The payment amount must be greater than the penalty.');
            }
            if ($amountApplied > $outstandingBalance) {
                abort(422, 'The payment exceeds the outstanding loan balance.');
            }

            $interestAlreadyPaid = (float) $loan->payments()->where('status', 'Posted')->sum('interest_portion');
            $remainingInterest = max(0, round((float) $loan->total_interest - $interestAlreadyPaid, 2));
            $interestPortion = min($amountApplied, $remainingInterest);
            $principalPortion = round($amountApplied - $interestPortion, 2);
            $newBalance = max(0, round($outstandingBalance - $amountApplied, 2));

            $payment = LoanPayment::create([
                'member_id' => $data['memberId'],
                'loan_application_id' => $loan->id,
                'payment_date' => $data['paymentDate'],
                'amount_paid' => $amountPaid,
                'principal_portion' => $principalPortion,
                'interest_portion' => $interestPortion,
                'penalty' => $penalty,
                'payment_method' => $data['paymentMethod'],
                'payroll_reference' => $data['payrollReference'] ?? null,
                'official_receipt_number' => $data['officialReceiptNumber'],
                'received_by' => $request->user()->full_name,
                'remarks' => $data['remarks'] ?? null,
                'status' => 'Posted',
            ]);
            $payment->update(['payment_reference_number' => 'GCGEA-PAY-'.now()->year.'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);

            $loan->update([
                'outstanding_balance' => $newBalance,
                'status' => $newBalance <= 0 ? 'Fully Paid' : ($loan->status === 'Released' ? 'Active' : $loan->status),
            ]);

            return $payment;
        });

        return new LoanPaymentResource($payment->load(['member', 'loan']));
    }
}
