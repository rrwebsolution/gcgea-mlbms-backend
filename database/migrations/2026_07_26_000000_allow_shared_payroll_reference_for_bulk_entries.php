<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk Payroll Deduction Entry creates one payroll_deduction_headers row per
 * member, all sharing the same payroll_reference for the batch. The original
 * unique constraint assumed Manual Entry's 1 reference = 1 member = 1 row and
 * would reject every row past the first in a bulk batch. Per-member
 * duplicate protection is already enforced separately by the existing
 * unique(['member_id', 'payroll_period']) constraint, so dropping this one
 * doesn't remove any real safety check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_deduction_headers', function (Blueprint $table) {
            $table->dropUnique('payroll_deduction_headers_payroll_reference_unique');
            $table->index('payroll_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_deduction_headers', function (Blueprint $table) {
            $table->dropIndex(['payroll_reference']);
            $table->unique('payroll_reference');
        });
    }
};
