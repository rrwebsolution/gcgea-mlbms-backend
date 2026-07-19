<?php

namespace Database\Seeders;

use App\Models\Loan;
use App\Models\LoanPayment;
use Illuminate\Database\Seeder;

/** One LoanPayment per amortization entry already marked "Paid" by LoanSeeder. */
class LoanPaymentSeeder extends Seeder
{
    public function run(): void
    {
        LoanPayment::query()->delete();

        $counter = 1;
        $loans = Loan::with(['schedule' => fn ($q) => $q->where('status', 'Paid')->orderBy('installment_number')])->get();

        foreach ($loans as $loan) {
            foreach ($loan->schedule as $entry) {
                $payment = LoanPayment::create([
                    'member_id' => $loan->member_id,
                    'loan_application_id' => $loan->id,
                    'payment_date' => $entry->due_date,
                    'amount_paid' => $entry->amount_paid,
                    'principal_portion' => $entry->principal,
                    'interest_portion' => $entry->interest,
                    'penalty' => $entry->penalty,
                    'payment_method' => $loan->payment_method,
                    'payroll_reference' => "PR-{$entry->due_date->format('Y-m')}-LN{$loan->id}",
                    'official_receipt_number' => 'OR-'.str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
                    'received_by' => 'Danilo T. Quiñones',
                    'status' => 'Posted',
                ]);
                $payment->update(['payment_reference_number' => 'GCGEA-LNP-'.$entry->due_date->year.'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);
                $counter++;
            }
        }
    }
}
