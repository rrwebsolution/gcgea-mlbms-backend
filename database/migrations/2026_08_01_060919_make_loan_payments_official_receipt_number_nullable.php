<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payments without a physical receipt on hand (e.g. payroll-deducted) now get an
 * auto-generated OR number assigned right after the row is created — see
 * LoanPaymentPoster::post() — which needs the column to briefly allow null.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE loan_payments ALTER COLUMN official_receipt_number DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loan_payments ALTER COLUMN official_receipt_number SET NOT NULL');
    }
};
