<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Singleton table — always exactly one row (id = 1), read/written via
     * LoanSetting::current(). Holds the global minimum-membership-months
     * floor plus the full Reloan Policy block.
     */
    public function up(): void
    {
        Schema::create('loan_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('minimum_membership_months')->default(6);

            $table->boolean('reloan_enabled')->default(true);
            $table->boolean('reloan_allow_after_fully_paid')->default(true);
            $table->boolean('reloan_allow_while_active')->default(true);
            $table->unsignedSmallInteger('reloan_min_paid_installments')->nullable()->default(6);
            $table->decimal('reloan_min_paid_percentage', 5, 2)->nullable();
            $table->boolean('reloan_require_no_overdue')->default(true);
            $table->boolean('reloan_require_no_penalty')->default(true);
            $table->boolean('reloan_deduct_previous_balance')->default(false);
            $table->unsignedSmallInteger('reloan_max_concurrent_active_loans')->default(1);
            $table->boolean('reloan_require_new_payslip')->default(true);
            $table->boolean('reloan_require_new_authorization')->default(true);
            $table->boolean('reloan_require_new_promissory_note')->default(true);
            $table->boolean('reloan_require_final_approval')->default(true);
            $table->boolean('reloan_require_board_resolution_above_limit')->default(true);

            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_settings');
    }
};
