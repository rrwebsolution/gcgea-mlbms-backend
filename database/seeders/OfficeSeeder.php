<?php

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Seeder;

/** Mirrors src/services/mock-data/offices.ts. */
class OfficeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $offices = [
            ['code' => 'MO', 'name' => "Mayor's Office", 'description' => 'Office of the City Mayor', 'status' => 'Active'],
            ['code' => 'CTO', 'name' => "City Treasurer's Office", 'description' => 'Handles city revenue collection and disbursement', 'status' => 'Active'],
            ['code' => 'CACCO', 'name' => 'City Accounting Office', 'description' => 'City accounting and auditing of disbursements', 'status' => 'Active'],
            ['code' => 'CBO', 'name' => 'City Budget Office', 'description' => 'Prepares and monitors the city budget', 'status' => 'Active'],
            ['code' => 'CASSO', 'name' => "City Assessor's Office", 'description' => 'Real property assessment and tax mapping', 'status' => 'Active'],
            ['code' => 'CHRMO', 'name' => 'City Human Resource Management Office', 'description' => 'Personnel administration and HR services', 'status' => 'Active'],
            ['code' => 'CPDO', 'name' => 'City Planning and Development Office', 'description' => 'Local development planning and monitoring', 'status' => 'Active'],
            ['code' => 'CEO', 'name' => "City Engineer's Office", 'description' => 'Infrastructure planning, design and maintenance', 'status' => 'Active'],
            ['code' => 'CHO', 'name' => 'City Health Office', 'description' => 'Public health services and programs', 'status' => 'Active'],
            ['code' => 'CSWDO', 'name' => 'City Social Welfare and Development Office', 'description' => 'Social welfare programs and services', 'status' => 'Active'],
            ['code' => 'CAGRO', 'name' => 'City Agriculture Office', 'description' => 'Agricultural extension and support services', 'status' => 'Active'],
            ['code' => 'CVETO', 'name' => 'City Veterinary Office', 'description' => 'Animal health and veterinary services', 'status' => 'Active'],
            ['code' => 'CENRO', 'name' => 'City Environment and Natural Resources Office', 'description' => 'Environmental management and protection', 'status' => 'Active'],
            ['code' => 'CDRRMO', 'name' => 'City Disaster Risk Reduction and Management Office', 'description' => 'Disaster preparedness and response', 'status' => 'Active'],
            ['code' => 'CLO', 'name' => 'City Legal Office', 'description' => 'Legal services to the city government', 'status' => 'Active'],
            ['code' => 'CGSO', 'name' => 'City General Services Office', 'description' => 'General services and property management', 'status' => 'Active'],
            ['code' => 'SP', 'name' => 'Sangguniang Panlungsod', 'description' => 'City legislative council secretariat', 'status' => 'Active'],
            ['code' => 'CIVIL-REG', 'name' => 'Office of the City Civil Registrar', 'description' => 'Civil registration services', 'status' => 'Active'],
            ['code' => 'COOP', 'name' => 'City Cooperative Development Office', 'description' => 'Cooperative development and monitoring', 'status' => 'Inactive'],
            ['code' => 'TOURISM', 'name' => 'City Tourism Office', 'description' => 'Tourism promotion and development', 'status' => 'Active'],
        ];

        foreach ($offices as $office) {
            Office::updateOrCreate(['code' => $office['code']], $office);
        }
    }
}
