<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_deduction_headers', function (Blueprint $table) {
            $table->id();
            $table->string('payroll_reference')->unique();
            $table->string('payroll_period', 7);
            $table->date('payroll_date');
            $table->foreignId('office_id')->constrained();
            $table->foreignId('member_id')->constrained();
            $table->text('remarks')->nullable();
            $table->decimal('total_deduction', 12, 2)->default(0);
            $table->string('status')->default('Draft');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'payroll_period']);
            $table->index(['status', 'payroll_date']);
        });

        Schema::create('payroll_deduction_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_deduction_header_id')->constrained()->cascadeOnDelete();
            $table->string('deduction_code');
            $table->decimal('amount', 12, 2);
            $table->foreignId('loan_id')->nullable()->constrained('loans');
            $table->foreignId('contribution_id')->nullable()->constrained();
            $table->foreignId('deduction_id')->nullable()->constrained();
            $table->foreignId('loan_payment_id')->nullable()->constrained();
            $table->timestamps();

            $table->unique(['payroll_deduction_header_id', 'deduction_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deduction_details');
        Schema::dropIfExists('payroll_deduction_headers');
    }
};
