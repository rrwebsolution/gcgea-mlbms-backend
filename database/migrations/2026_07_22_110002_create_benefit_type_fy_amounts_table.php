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
        // Fiscal-year-indexed 100% base amount for a BenefitType (Resolution No.
        // 24-2026, Table 2 — the Cash Pabaon Program's base escalates FY2026
        // ₱70,000 -> FY2029+ ₱100,000). Only benefit types that need FY escalation
        // have rows here; others keep using their flat maximum_amount as the base.
        Schema::create('benefit_type_fy_amounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year')->nullable(); // null = catch-all "and beyond"
            $table->decimal('base_amount', 12, 2);
            $table->timestamps();

            $table->index('benefit_type_id');
            $table->unique(['benefit_type_id', 'fiscal_year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_type_fy_amounts');
    }
};
