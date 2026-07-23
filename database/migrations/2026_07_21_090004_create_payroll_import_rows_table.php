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
        Schema::create('payroll_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('payroll_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data');

            // Deliberately plain strings, not foreignId — a row must stay
            // inspectable in history even after a referenced record is later
            // voided/archived elsewhere, and rollback needs to re-fetch and
            // compare rather than cascade-delete.
            $table->string('matched_member_id')->nullable();
            $table->string('matched_loan_id')->nullable();
            $table->string('match_method')->nullable(); // member_number | employee_number | name | none

            $table->string('validation_category'); // Valid | Warning | Invalid
            $table->json('validation_reasons')->nullable();
            $table->boolean('excluded')->default(false);
            $table->string('row_status')->default('Pending'); // Pending | Imported | Skipped | Failed | RolledBack

            $table->string('created_loan_payment_id')->nullable();
            $table->string('created_contribution_id')->nullable();
            $table->string('created_deduction_id')->nullable();

            // Rollback-safety snapshot: the affected loan's balance/status
            // immediately before and after this row's write, captured inside the
            // same DB transaction that performed the write, so rollback can
            // verify nothing else touched the loan since.
            $table->decimal('loan_principal_balance_before', 12, 2)->nullable();
            $table->decimal('loan_interest_balance_before', 12, 2)->nullable();
            $table->string('loan_status_before')->nullable();
            $table->decimal('loan_principal_balance_after', 12, 2)->nullable();
            $table->decimal('loan_interest_balance_after', 12, 2)->nullable();
            $table->string('loan_status_after')->nullable();

            $table->timestamps();

            $table->index(['batch_id', 'validation_category']);
            $table->index(['batch_id', 'row_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_import_rows');
    }
};
