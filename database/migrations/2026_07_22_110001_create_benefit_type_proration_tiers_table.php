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
        // Prorates a BenefitType's payout by the member's paid contribution-months
        // (Resolution No. 24-2026, Table 1 — Core Benefits — and Table 2 — Cash
        // Pabaon Program). A benefit type with no rows here keeps its existing
        // flat maximum_amount behavior untouched.
        Schema::create('benefit_type_proration_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_months');
            $table->unsignedInteger('max_months')->nullable(); // null = open-ended ("and beyond")
            $table->decimal('percentage', 5, 2);
            $table->timestamps();

            $table->index('benefit_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_type_proration_tiers');
    }
};
