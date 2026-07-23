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
        Schema::create('payroll_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('payroll_period'); // "YYYY-MM"
            $table->string('payroll_reference')->nullable();
            $table->json('column_mapping')->nullable();
            $table->json('sheet_meta')->nullable();
            $table->string('status')->default('Uploaded'); // Uploaded -> Previewed -> Committed -> RolledBack
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->foreignId('committed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('committed_at')->nullable();
            $table->foreignId('rolled_back_by_user_id')->nullable()->constrained('users');
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('rollback_reason')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->decimal('total_loan_payments', 14, 2)->default(0);
            $table->decimal('total_contributions', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->timestamps();

            $table->index('payroll_period');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_import_batches');
    }
};
