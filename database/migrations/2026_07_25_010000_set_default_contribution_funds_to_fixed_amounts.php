<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'Mortuary Fund' => [25, 1],
            'Emergency Fund' => [30, 2],
            'Operational Fund' => [15, 3],
            'Retirement Fund' => [15, 4],
            'Loan Investment Fund' => [15, 5],
        ];

        foreach ($defaults as $fundName => [$value, $order]) {
            DB::table('contribution_funds')->where('fund_name', $fundName)->update([
                'allocation_type' => 'Fixed Amount',
                'allocation_value' => $value,
                'is_enabled' => true,
                'display_order' => $order,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $percentages = [
            'Mortuary Fund' => 25,
            'Emergency Fund' => 20,
            'Operational Fund' => 15,
            'Retirement Fund' => 20,
            'Loan Investment Fund' => 20,
        ];

        foreach ($percentages as $fundName => $value) {
            DB::table('contribution_funds')->where('fund_name', $fundName)->update([
                'allocation_type' => 'Percentage',
                'allocation_value' => $value,
                'updated_at' => now(),
            ]);
        }
    }
};
