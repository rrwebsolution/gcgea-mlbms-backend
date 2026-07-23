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
        Schema::table('members', function (Blueprint $table) {
            // Monthly net take-home pay — drives Solidarity Cash Assistance Loan
            // income-bracket lookup (Resolution No. 24-2026, Table 3). Nullable:
            // not every member has this on file yet.
            $table->decimal('net_pay', 12, 2)->nullable()->after('membership_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('net_pay');
        });
    }
};
