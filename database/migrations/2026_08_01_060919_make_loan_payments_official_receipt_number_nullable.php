<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments without a physical receipt on hand (e.g. payroll-deducted) now get an
 * auto-generated OR number assigned right after the row is created — see
 * LoanPaymentPoster::post() — which needs the column to briefly allow null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->string('official_receipt_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->string('official_receipt_number')->nullable(false)->change();
        });
    }
};
