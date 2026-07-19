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
        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_reference_number')->nullable()->unique();
            $table->foreignId('member_id')->constrained();
            $table->foreignId('loan_application_id')->constrained('loans');
            $table->date('payment_date');
            $table->decimal('amount_paid', 12, 2);
            $table->decimal('principal_portion', 12, 2);
            $table->decimal('interest_portion', 12, 2);
            $table->decimal('penalty', 12, 2)->default(0);
            $table->string('payment_method');
            $table->string('payroll_reference')->nullable();
            $table->string('official_receipt_number');
            $table->string('received_by')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->default('Posted');
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
    }
};
