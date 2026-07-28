<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('system_settings')->where('section', 'contribution')->first();
        $value = $row ? (json_decode((string) $row->value, true) ?: []) : [];
        $value['defaultMonthlyContribution'] = 100;
        $value['contributionDueDay'] = 26;
        $value['enableAutomaticFundAllocation'] = true;
        $value['requireAllocationValidation'] = true;

        DB::table('system_settings')->updateOrInsert(
            ['section' => 'contribution'],
            [
                'value' => json_encode($value),
                'updated_by' => 'System Migration',
                'created_at' => $row?->created_at ?? now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        // Preserve the organization's current settings on rollback.
    }
};
