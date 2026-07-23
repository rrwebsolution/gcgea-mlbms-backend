<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('loan_settings')->update([
            'reloan_enabled' => true,
            'reloan_allow_while_active' => true,
            'reloan_min_paid_installments' => 6,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Eligibility policy changes are intentionally not reversed.
    }
};
