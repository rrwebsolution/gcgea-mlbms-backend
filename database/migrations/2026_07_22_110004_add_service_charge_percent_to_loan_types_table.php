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
        Schema::table('loan_types', function (Blueprint $table) {
            // 1%-of-gross-principal service charge (Resolution No. 24-2026, Table 3),
            // distinct from the existing flat-currency processing_fee. Nullable —
            // only loan types that charge a percentage set this.
            $table->decimal('service_charge_percent', 5, 2)->nullable()->after('processing_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            $table->dropColumn('service_charge_percent');
        });
    }
};
