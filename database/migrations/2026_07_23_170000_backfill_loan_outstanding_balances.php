<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $loans = DB::table('loans')
            ->whereIn('status', ['Released', 'Active', 'Overdue', 'Restructured'])
            ->where(function ($query) {
                $query->whereNull('outstanding_balance')->orWhere('outstanding_balance', '<=', 0);
            })
            ->get(['id', 'principal', 'total_interest']);

        foreach ($loans as $loan) {
            $paid = DB::table('loan_payments')
                ->where('loan_application_id', $loan->id)
                ->where('status', 'Posted')
                ->selectRaw('COALESCE(SUM(principal_portion), 0) AS principal_paid, COALESCE(SUM(interest_portion), 0) AS interest_paid')
                ->first();

            $principalBalance = max(0, round((float) $loan->principal - (float) $paid->principal_paid, 2));
            $interestBalance = max(0, round((float) $loan->total_interest - (float) $paid->interest_paid, 2));

            DB::table('loans')->where('id', $loan->id)->update([
                'principal_balance' => $principalBalance,
                'interest_balance' => $interestBalance,
                'outstanding_balance' => round($principalBalance + $interestBalance, 2),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Balance backfills represent real ledger state and are intentionally not reversed.
    }
};
