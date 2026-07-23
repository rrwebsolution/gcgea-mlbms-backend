<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_settings', function (Blueprint $table) {
            $table->boolean('require_paid_contributions')->default(true);
            $table->unsignedSmallInteger('minimum_paid_contribution_months')->default(6);
            $table->decimal('required_monthly_dues_amount', 12, 2)->default(100);
            $table->boolean('require_consecutive_contribution_months')->default(true);
            $table->boolean('apply_contribution_rule_to_reloan')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('loan_settings', function (Blueprint $table) {
            $table->dropColumn([
                'require_paid_contributions',
                'minimum_paid_contribution_months',
                'required_monthly_dues_amount',
                'require_consecutive_contribution_months',
                'apply_contribution_rule_to_reloan',
            ]);
        });
    }
};
