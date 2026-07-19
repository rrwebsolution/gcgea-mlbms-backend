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
        Schema::create('loan_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('min_amount', 12, 2)->default(0);
            $table->decimal('max_amount', 12, 2)->default(0);
            $table->decimal('default_interest_rate', 5, 2)->default(0);
            $table->string('interest_method');
            $table->decimal('processing_fee', 12, 2)->default(0);
            $table->unsignedInteger('max_term_months')->default(0);
            $table->unsignedInteger('required_membership_months')->default(0);
            $table->unsignedInteger('required_contribution_months')->default(0);
            $table->boolean('allow_existing_active_loan')->default(false);
            $table->string('status')->default('Active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_types');
    }
};
