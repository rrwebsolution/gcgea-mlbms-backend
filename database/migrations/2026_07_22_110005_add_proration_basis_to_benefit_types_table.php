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
        Schema::table('benefit_types', function (Blueprint $table) {
            // Which contribution ledger prorationTiers() counts months against:
            // "dues" (contributions table — Core Benefits, Table 1) or "pabaon"
            // (deductions table, deduction_type code=pabaon — Cash Pabaon
            // Program, Table 2). Null for benefit types with no proration tiers.
            $table->string('proration_basis')->nullable()->after('maximum_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('benefit_types', function (Blueprint $table) {
            $table->dropColumn('proration_basis');
        });
    }
};
