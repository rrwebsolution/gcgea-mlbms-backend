<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('loans')
            ->whereIn('status', ['Released', 'Active', 'Overdue', 'Restructured', 'Fully Paid'])
            ->orderBy('id')
            ->chunkById(100, function ($loans): void {
                foreach ($loans as $loan) {
                    $paid = DB::table('loan_payments')
                        ->where('loan_application_id', $loan->id)
                        ->where('status', 'Posted')
                        ->selectRaw('COALESCE(SUM(principal_portion), 0) AS principal_paid, COALESCE(SUM(interest_portion), 0) AS interest_paid')
                        ->first();

                    $principalBalance = max(
                        0,
                        round((float) $loan->principal - (float) $paid->principal_paid, 2)
                    );
                    $interestBalance = max(
                        0,
                        round((float) $loan->total_interest - (float) $paid->interest_paid, 2)
                    );
                    $outstandingBalance = round($principalBalance + $interestBalance, 2);

                    DB::table('loans')->where('id', $loan->id)->update([
                        'principal_balance' => $principalBalance,
                        'interest_balance' => $interestBalance,
                        'outstanding_balance' => $outstandingBalance,
                        'status' => $outstandingBalance <= 0 ? 'Fully Paid' : $loan->status,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Reconciliation reflects posted ledger transactions and is not reversible.
    }
};
