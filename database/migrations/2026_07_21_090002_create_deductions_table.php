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
        Schema::create('deductions', function (Blueprint $table) {
            $table->id();
            // Nullable briefly at insert time — filled in from the row's own id
            // right after create(), same convention as contributions/loan_payments.
            $table->string('reference_number')->nullable()->unique();
            $table->foreignId('member_id')->constrained();
            $table->foreignId('deduction_type_id')->constrained();
            $table->string('period'); // "YYYY-MM", same plain-string convention as contribution_period
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payroll_reference')->nullable();
            $table->text('remarks')->nullable();
            $table->string('encoded_by');
            $table->string('status')->default('Posted');
            $table->string('void_reason')->nullable();
            $table->string('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['deduction_type_id', 'period']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deductions');
    }
};
