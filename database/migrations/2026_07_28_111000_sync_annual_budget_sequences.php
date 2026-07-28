<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "SELECT setval(
                pg_get_serial_sequence('annual_budgets', 'id'),
                COALESCE((SELECT MAX(id) FROM annual_budgets), 1),
                EXISTS(SELECT 1 FROM annual_budgets)
            )"
        );
        DB::statement(
            "SELECT setval(
                pg_get_serial_sequence('annual_budget_items', 'id'),
                COALESCE((SELECT MAX(id) FROM annual_budget_items), 1),
                EXISTS(SELECT 1 FROM annual_budget_items)
            )"
        );
    }

    public function down(): void
    {
        // Sequence synchronization is intentionally not reversed.
    }
};
