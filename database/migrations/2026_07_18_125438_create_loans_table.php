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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            // Nullable briefly at insert time, filled in right after create() from the row's own id.
            $table->string('application_number')->nullable()->unique();
            $table->date('application_date');
            $table->foreignId('member_id')->constrained();
            $table->foreignId('loan_type_id')->constrained();

            $table->decimal('requested_amount', 12, 2);
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->unsignedInteger('term_months');
            $table->decimal('interest_rate', 6, 3);
            $table->decimal('processing_fee', 10, 2);
            $table->text('purpose');
            $table->string('payment_method');
            $table->date('first_due_date');
            $table->date('maturity_date')->nullable();

            $table->decimal('principal', 12, 2);
            $table->decimal('total_interest', 12, 2);
            $table->decimal('net_proceeds', 12, 2);
            $table->decimal('total_amount_payable', 12, 2);
            $table->decimal('monthly_amortization', 12, 2);
            $table->decimal('outstanding_balance', 12, 2)->default(0);

            $table->string('status')->default('Draft');
            $table->string('assigned_officer')->nullable();

            $table->json('eligibility')->nullable();
            $table->boolean('eligibility_overridden')->default(false);
            $table->text('eligibility_override_reason')->nullable();
            $table->json('requirements')->nullable();

            $table->date('release_date')->nullable();
            $table->string('release_reference_number')->nullable();
            $table->string('release_method')->nullable();
            $table->decimal('actual_released_amount', 12, 2)->nullable();
            $table->text('release_remarks')->nullable();

            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
