<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_settings', function (Blueprint $table) {
            $table->boolean('lock_first_solidarity_loan')->default(true);
            $table->decimal('first_solidarity_loan_amount', 12, 2)->default(20000);
        });
    }

    public function down(): void
    {
        Schema::table('loan_settings', function (Blueprint $table) {
            $table->dropColumn(['lock_first_solidarity_loan', 'first_solidarity_loan_amount']);
        });
    }
};
