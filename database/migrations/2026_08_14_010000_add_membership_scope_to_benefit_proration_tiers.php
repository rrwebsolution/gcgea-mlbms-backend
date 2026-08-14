<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benefit_type_proration_tiers', function (Blueprint $table) {
            $table->string('membership_scope')->default('all')->after('benefit_type_id');
            $table->index(['benefit_type_id', 'membership_scope']);
        });

        $cashPabaonId = DB::table('benefit_types')->where('name', 'Cash Pabaon Program')->value('id');
        if (! $cashPabaonId) {
            return;
        }

        // The previously seeded 14-tier schedule is Resolution 27-2026 and
        // applies only to members admitted from September 1, 2026 onward.
        DB::table('benefit_type_proration_tiers')
            ->where('benefit_type_id', $cashPabaonId)
            ->update(['membership_scope' => 'new']);

        $now = now();
        DB::table('benefit_type_proration_tiers')->insert([
            ['benefit_type_id' => $cashPabaonId, 'membership_scope' => 'legacy', 'min_months' => 12, 'max_months' => 24, 'percentage' => 25, 'created_at' => $now, 'updated_at' => $now],
            ['benefit_type_id' => $cashPabaonId, 'membership_scope' => 'legacy', 'min_months' => 25, 'max_months' => 60, 'percentage' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['benefit_type_id' => $cashPabaonId, 'membership_scope' => 'legacy', 'min_months' => 61, 'max_months' => 96, 'percentage' => 75, 'created_at' => $now, 'updated_at' => $now],
            ['benefit_type_id' => $cashPabaonId, 'membership_scope' => 'legacy', 'min_months' => 97, 'max_months' => null, 'percentage' => 100, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('benefit_type_proration_tiers', function (Blueprint $table) {
            $table->dropIndex(['benefit_type_id', 'membership_scope']);
            $table->dropColumn('membership_scope');
        });
    }
};
