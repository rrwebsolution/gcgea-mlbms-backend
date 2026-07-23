<?php

namespace App\Http\Controllers;

use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\ReloanEligibilityService;
use App\Services\ReloanService;
use Illuminate\Http\Request;

class ReloanController extends Controller
{
    public function __construct(
        private readonly ReloanEligibilityService $eligibilityService,
        private readonly ReloanService $reloanService,
    ) {}

    /**
     * Pre-check used to enable/disable the Reloan button and populate the
     * Loan Details "ReLoan Eligibility" card, before any draft exists.
     * Requested amount/term aren't known yet, so amount/term-dependent
     * checks are skipped here — they run for real once the draft's own
     * amount/term are entered (LoanController::update()).
     */
    public function eligibility(Request $request, Loan $loan)
    {
        if (! $request->user()->hasPermission('loans.view')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $member = $loan->member;
        $loanType = $loan->loanType;

        $result = $this->eligibilityService->canCreateReloan($loan, $member, $loanType, (float) $loan->outstanding_balance ?: (float) $loanType->min_amount, $loanType->max_term_months);

        return response()->json([
            'eligible' => $result['verdict'] !== 'Not Eligible',
            'verdict' => $result['verdict'],
            'checks' => $result['checks'],
            'previousObligation' => $result['previousObligation'],
        ]);
    }

    public function createDraft(Request $request, Loan $loan)
    {
        if (! $request->user()->hasPermission('loans.reloan')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $result = $this->eligibilityService->canCreateReloan(
            $loan,
            $loan->member,
            $loan->loanType,
            (float) $loan->outstanding_balance ?: (float) $loan->loanType->min_amount,
            $loan->loanType->max_term_months,
        );
        if ($result['verdict'] === 'Not Eligible') {
            $failedPaidMonths = collect($result['checks'])->first(
                fn ($check) => $check['label'] === 'Six-Month Previous Loan Payment' && ! $check['passed']
            );
            abort(422, $failedPaidMonths['detail'] ?? collect($result['checks'])->firstWhere('passed', false)['detail'] ?? 'This loan is not eligible for reloan.');
        }

        $reloan = $this->reloanService->createDraft($loan, $request->user());

        return new LoanResource($reloan->load(['member.office', 'loanType', 'previousLoan']));
    }
}
