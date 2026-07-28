<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_deduction_headers', function (Blueprint $table) {
            $table->decimal('loanable_amount', 12, 2)->nullable()->after('remarks');
            $table->decimal('outstanding_interest', 12, 2)->nullable()->after('loanable_amount');
            $table->decimal('outstanding_balance', 12, 2)->nullable()->after('outstanding_interest');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_deduction_headers', function (Blueprint $table) {
            $table->dropColumn(['loanable_amount', 'outstanding_interest', 'outstanding_balance']);
        });
    }
};
