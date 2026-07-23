<?php

namespace App\Http\Controllers;

use App\Exceptions\LoanPaymentPostingException;
use App\Http\Resources\LoanPaymentResource;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Services\LoanPaymentPoster;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoanPaymentController extends Controller
{
    public function __construct(private readonly LoanPaymentPoster $poster) {}

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

        try {
            $payment = DB::transaction(function () use ($request, $data) {
                $loan = Loan::lockForUpdate()->findOrFail($data['loanApplicationId']);

                if ((string) $loan->member_id !== (string) $data['memberId']) {
                    abort(422, 'The selected loan does not belong to this member.');
                }

                $result = $this->poster->post($loan, [
                    'amountPaid' => $data['amountPaid'],
                    'penalty' => $data['penalty'] ?? 0,
                ], [
                    'paymentDate' => $data['paymentDate'],
                    'paymentMethod' => $data['paymentMethod'],
                    'payrollReference' => $data['payrollReference'] ?? null,
                    'officialReceiptNumber' => $data['officialReceiptNumber'],
                    'receivedBy' => $request->user()->full_name,
                    'remarks' => $data['remarks'] ?? null,
                ]);

                return $result->payment;
            });
        } catch (LoanPaymentPostingException $e) {
            abort(422, $e->getMessage());
        }

        return new LoanPaymentResource($payment->load(['member', 'loan']));
    }
}
