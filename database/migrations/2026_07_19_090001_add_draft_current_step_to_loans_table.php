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
        Schema::table('loans', function (Blueprint $table) {
            $table->unsignedSmallInteger('draft_current_step')->nullable()->default(1)->after('status');
        });

        // A draft only needs member_id (see LoanRequest) — the rest of these
        // were NOT NULL because every real loan has them computed at store()
        // time. Drafts saved before Step 2/4 legitimately don't have them yet.
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('loan_type_id')->nullable()->change();
            $table->decimal('requested_amount', 12, 2)->nullable()->change();
            $table->unsignedInteger('term_months')->nullable()->change();
            $table->decimal('interest_rate', 6, 3)->nullable()->change();
            $table->decimal('processing_fee', 10, 2)->nullable()->change();
            $table->text('purpose')->nullable()->change();
            $table->string('payment_method')->nullable()->change();
            $table->date('first_due_date')->nullable()->change();
            $table->decimal('principal', 12, 2)->nullable()->change();
            $table->decimal('total_interest', 12, 2)->nullable()->change();
            $table->decimal('net_proceeds', 12, 2)->nullable()->change();
            $table->decimal('total_amount_payable', 12, 2)->nullable()->change();
            $table->decimal('monthly_amortization', 12, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('loan_type_id')->nullable(false)->change();
            $table->decimal('requested_amount', 12, 2)->nullable(false)->change();
            $table->unsignedInteger('term_months')->nullable(false)->change();
            $table->decimal('interest_rate', 6, 3)->nullable(false)->change();
            $table->decimal('processing_fee', 10, 2)->nullable(false)->change();
            $table->text('purpose')->nullable(false)->change();
            $table->string('payment_method')->nullable(false)->change();
            $table->date('first_due_date')->nullable(false)->change();
            $table->decimal('principal', 12, 2)->nullable(false)->change();
            $table->decimal('total_interest', 12, 2)->nullable(false)->change();
            $table->decimal('net_proceeds', 12, 2)->nullable(false)->change();
            $table->decimal('total_amount_payable', 12, 2)->nullable(false)->change();
            $table->decimal('monthly_amortization', 12, 2)->nullable(false)->change();
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('draft_current_step');
        });
    }
};
