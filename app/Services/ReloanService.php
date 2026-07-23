<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the linked Reloan draft. Everything past draft creation (editing,
 * eligibility re-check, computation, submission) reuses the existing
 * LoanController::update()/store() machinery, made reloan-aware — a reloan
 * is a Loan row, not a separate entity.
 */
class ReloanService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function createDraft(Loan $previousLoan, User $actor): Loan
    {
        $policy = LoanSetting::current();

        if (! $policy->reloan_enabled) {
            throw ValidationException::withMessages(['reloan' => 'Reloans are currently disabled by policy.']);
        }

        $hasPending = Loan::where('previous_loan_id', $previousLoan->id)
            ->whereNotIn('status', ['Rejected', 'Cancelled'])
            ->exists();
        if ($hasPending) {
            throw ValidationException::withMessages(['reloan' => 'A reloan application already exists for this loan.']);
        }

        return DB::transaction(function () use ($previousLoan, $actor) {
            $rootLoanId = $previousLoan->root_loan_id ?? $previousLoan->id;
            $sequence = (int) Loan::where('root_loan_id', $rootLoanId)->max('reloan_sequence') + 1;

            $reloan = Loan::create([
                'application_date' => now(),
                'member_id' => $previousLoan->member_id,
                'loan_type_id' => $previousLoan->loan_type_id,
                'payment_method' => $previousLoan->payment_method,
                'purpose' => null,
                'requested_amount' => null,
                'term_months' => null,
                'interest_rate' => null,
                'processing_fee' => null,
                'first_due_date' => null,
                'principal' => null,
                'total_interest' => null,
                'net_proceeds' => null,
                'total_amount_payable' => null,
                'monthly_amortization' => null,
                'outstanding_balance' => 0,
                'status' => 'Draft',
                'application_type' => 'reloan',
                'previous_loan_id' => $previousLoan->id,
                'root_loan_id' => $rootLoanId,
                'reloan_sequence' => $sequence,
                'draft_current_step' => 1,
                'assigned_officer' => $actor->full_name,
                'requirements' => [],
                'created_by' => $actor->full_name,
            ]);
            $reloan->update(['application_number' => 'GCGEA-LN-'.now()->year.'-'.str_pad((string) $reloan->id, 6, '0', STR_PAD_LEFT)]);

            $this->auditLog->record($actor, $reloan, 'reloan_draft_created', "Linked to previous loan {$previousLoan->application_number}.");

            return $reloan;
        });
    }
}
