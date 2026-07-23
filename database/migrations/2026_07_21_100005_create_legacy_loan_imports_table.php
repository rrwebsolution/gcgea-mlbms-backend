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
        Schema::create('legacy_loan_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('member_import_batches')->cascadeOnDelete();
            $table->foreignId('member_import_row_id')->constrained('member_import_rows')->cascadeOnDelete();
            $table->unsignedBigInteger('created_member_id')->nullable();

            // Raw legacy sheet values — stored as text since source data is
            // frequently inconsistent (dates as free text, blank cells,
            // stray currency symbols). This table is staging only and never
            // writes to the real `loans` table.
            $table->string('cash_pabaon')->nullable();
            $table->decimal('cash_pabaon_amount', 12, 2)->nullable();
            $table->string('loan_start')->nullable();
            $table->date('loan_start_date')->nullable();
            $table->string('solidarity_assistance_loan')->nullable();
            $table->decimal('solidarity_assistance_loan_amount', 12, 2)->nullable();
            $table->string('no_of_months')->nullable();
            $table->unsignedSmallInteger('no_of_months_parsed')->nullable();
            $table->string('monthly_amort')->nullable();
            $table->decimal('monthly_amort_amount', 12, 2)->nullable();
            $table->text('legacy_notes')->nullable(); // from "2015 Resolution" when present

            $table->string('review_status')->default('Unreviewed'); // Unreviewed | Reviewed | Dismissed
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index('review_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legacy_loan_imports');
    }
};
