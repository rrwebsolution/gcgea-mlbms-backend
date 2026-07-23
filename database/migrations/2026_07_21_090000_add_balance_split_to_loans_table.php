<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('principal_balance', 12, 2)->default(0)->after('outstanding_balance');
            $table->decimal('interest_balance', 12, 2)->default(0)->after('principal_balance');
        });

        // No historical principal/interest split exists for loans that already
        // have payments posted, so the entire existing balance is attributed to
        // principal on backfill — the split only becomes accurate going forward.
        DB::table('loans')->update([
            'principal_balance' => DB::raw('outstanding_balance'),
            'interest_balance' => 0,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['principal_balance', 'interest_balance']);
        });
    }
};
