<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('grace_period_days')->default(5);
            $table->string('default_payment_method')->default('Payroll Deduction');
            $table->string('rounding_rule')->default('Nearest Centavo');
            $table->boolean('allow_eligibility_override')->default(true);
            $table->boolean('require_approval')->default(true);
            $table->boolean('require_release_confirmation')->default(true);
            $table->boolean('allow_partial_payment')->default(true);
            $table->boolean('allow_advance_payment')->default(true);
            $table->boolean('allow_loan_restructuring')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('loan_settings', function (Blueprint $table) {
            $table->dropColumn([
                'grace_period_days', 'default_payment_method', 'rounding_rule',
                'allow_eligibility_override', 'require_approval', 'require_release_confirmation',
                'allow_partial_payment', 'allow_advance_payment', 'allow_loan_restructuring',
            ]);
        });
    }
};
