<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cashPabaonId = DB::table('benefit_types')->where('name', 'Cash Pabaon Program')->value('id');
        if (! $cashPabaonId) {
            return;
        }

        DB::table('benefit_type_fy_amounts')->updateOrInsert(
            ['benefit_type_id' => $cashPabaonId, 'fiscal_year' => 2025],
            ['base_amount' => 60000, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        $cashPabaonId = DB::table('benefit_types')->where('name', 'Cash Pabaon Program')->value('id');
        DB::table('benefit_type_fy_amounts')
            ->where('benefit_type_id', $cashPabaonId)
            ->where('fiscal_year', 2025)
            ->delete();
    }
};
