<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_settings', function (Blueprint $table) {
            $table->decimal('default_penalty_rate', 5, 2)->default(2)->after('minimum_membership_months');
        });
    }

    public function down(): void
    {
        Schema::table('loan_settings', function (Blueprint $table) {
            $table->dropColumn('default_penalty_rate');
        });
    }
};
