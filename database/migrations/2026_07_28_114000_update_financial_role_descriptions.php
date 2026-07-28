<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $descriptions = [
            'treasurer' => 'Prepares annual budgets and disbursements, manages collections, and records approved payments.',
            'approving_officer' => 'Reviews and decides loan, benefit, annual budget, and disbursement submissions.',
            'auditor_viewer' => 'Read-only access to operational and financial records for audit and oversight.',
        ];

        foreach ($descriptions as $code => $description) {
            DB::table('roles')->where('code', $code)->update([
                'description' => $description,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $descriptions = [
            'treasurer' => 'Handles contributions, collections, and payment posting.',
            'approving_officer' => 'Reviews and approves loans and benefit applications.',
            'auditor_viewer' => 'Read-only access for audit and oversight purposes.',
        ];

        foreach ($descriptions as $code => $description) {
            DB::table('roles')->where('code', $code)->update([
                'description' => $description,
                'updated_at' => now(),
            ]);
        }
    }
};
