<?php

namespace Database\Seeders;

use App\Models\DeductionType;
use Illuminate\Database\Seeder;

class DeductionTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $deductionTypes = [
            [
                'name' => 'Pabaon',
                'code' => 'pabaon',
                'description' => 'Send-off/assistance deduction collected via payroll.',
                'is_active' => true,
                'sort_order' => 1,
            ],
        ];

        foreach ($deductionTypes as $deductionType) {
            DeductionType::updateOrCreate(['code' => $deductionType['code']], $deductionType);
        }
    }
}
