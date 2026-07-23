<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tiers a LoanType's loanable amount by the member's monthly net take-home
        // pay (Resolution No. 24-2026, Table 3 — Solidarity Cash Assistance Loan).
        // A loan type with no rows here keeps its existing flat min/max_amount
        // behavior untouched.
        Schema::create('loan_type_income_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_net_pay', 12, 2);
            $table->decimal('max_net_pay', 12, 2)->nullable(); // null = open-ended ("and above")
            $table->decimal('loanable_amount', 12, 2);
            $table->timestamps();

            $table->index('loan_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_type_income_brackets');
    }
};
