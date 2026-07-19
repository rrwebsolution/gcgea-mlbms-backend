<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Mirrors src/services/mock-data/users.ts — same people, same shared dev password
 * as the old mock auth (Gcgea@2026), so existing test logins keep working against
 * the real backend. Role ids below match the ids assigned in RoleSeeder.
 */
class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = Hash::make('Gcgea@2026');

        $users = [
            ['username' => 'mcsantos', 'full_name' => 'Maria Corazon D. Santos', 'email' => 'mcsantos@gcgea.gingoog.gov.ph', 'contact_number' => '09171234501', 'role_id' => 1, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => false, 'remarks' => null, 'status' => 'Active'],
            // ['username' => 'rochotorena', 'full_name' => 'Ramil B. Ochotorena', 'email' => 'rochotorena@gcgea.gingoog.gov.ph', 'contact_number' => '09171234502', 'role_id' => 1, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => false, 'remarks' => null, 'status' => 'Active'],
            // ['username' => 'alfernandez', 'full_name' => 'Ana Liza P. Fernandez', 'email' => 'alfernandez@gcgea.gingoog.gov.ph', 'contact_number' => '09171234503', 'role_id' => 2, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => false, 'remarks' => null, 'status' => 'Active'],
            // ['username' => 'jaramoso', 'full_name' => 'Joel A. Ramoso', 'email' => 'jaramoso@gcgea.gingoog.gov.ph', 'contact_number' => '09171234504', 'role_id' => 2, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => true, 'remarks' => null, 'status' => 'Active'],
            // ['username' => 'escabahug', 'full_name' => 'Erwin S. Cabahug', 'email' => 'escabahug@gcgea.gingoog.gov.ph', 'contact_number' => '09171234505', 'role_id' => 3, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => false, 'remarks' => null, 'status' => 'Active'],
            // ['username' => 'pjutlang', 'full_name' => 'Precious Joy M. Utlang', 'email' => 'pjutlang@gcgea.gingoog.gov.ph', 'contact_number' => '09171234506', 'role_id' => 3, 'additional_role_ids' => [4], 'allowed' => [], 'denied' => [], 'require_password_change' => false, 'remarks' => 'Also handles Treasury functions for the Odiongan satellite office.', 'status' => 'Active'],
            // ['username' => 'dtquinones', 'full_name' => 'Danilo T. Quiñones', 'email' => 'dtquinones@gcgea.gingoog.gov.ph', 'contact_number' => '09171234507', 'role_id' => 4, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => false, 'remarks' => null, 'status' => 'Active'],
            // ['username' => 'gbnacua', 'full_name' => 'Girlie B. Nacua', 'email' => 'gbnacua@gcgea.gingoog.gov.ph', 'contact_number' => '09171234508', 'role_id' => 5, 'additional_role_ids' => [], 'allowed' => ['benefits.override_eligibility'], 'denied' => [], 'require_password_change' => false, 'remarks' => 'Granted eligibility override rights per HR memo 2026-014.', 'status' => 'Active'],
            // ['username' => 'rcmagabo', 'full_name' => 'Reynaldo C. Mag-abo', 'email' => 'rcmagabo@gcgea.gingoog.gov.ph', 'contact_number' => '09171234509', 'role_id' => 6, 'additional_role_ids' => [], 'allowed' => [], 'denied' => ['loans.approve'], 'require_password_change' => false, 'remarks' => 'Loan approval access suspended pending COI review — recommend only until cleared.', 'status' => 'Active'],
            // ['username' => 'tgabellanosa', 'full_name' => 'Teresita G. Abellanosa', 'email' => 'tgabellanosa@gcgea.gingoog.gov.ph', 'contact_number' => '09171234510', 'role_id' => 7, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => false, 'remarks' => null, 'status' => 'Active'],
            // ['username' => 'mrdensing', 'full_name' => 'Michael Angelo R. Densing', 'email' => 'mrdensing@gcgea.gingoog.gov.ph', 'contact_number' => '09171234511', 'role_id' => 3, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => false, 'remarks' => 'Account deactivated — resigned effective December 2025.', 'status' => 'Inactive'],
            // ['username' => 'flybanez', 'full_name' => 'Ferdinand L. Ybañez', 'email' => 'flybanez@gcgea.gingoog.gov.ph', 'contact_number' => '09171234512', 'role_id' => 8, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => false, 'remarks' => null, 'status' => 'Active'],
            // ['username' => 'cvbontuyan', 'full_name' => 'Corazon V. Bontuyan', 'email' => 'cvbontuyan@gcgea.gingoog.gov.ph', 'contact_number' => '09171234513', 'role_id' => 9, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => false, 'remarks' => null, 'status' => 'Active'],
            // ['username' => 'jsamora', 'full_name' => 'Jerico S. Amora', 'email' => 'jsamora@gcgea.gingoog.gov.ph', 'contact_number' => '09171234514', 'role_id' => 10, 'additional_role_ids' => [], 'allowed' => [], 'denied' => [], 'require_password_change' => true, 'remarks' => null, 'status' => 'Active'],
        ];

        foreach ($users as $definition) {
            $user = User::updateOrCreate(
                ['username' => $definition['username']],
                [
                    'full_name' => $definition['full_name'],
                    'email' => $definition['email'],
                    'contact_number' => $definition['contact_number'],
                    'password' => $password,
                    'role_id' => $definition['role_id'],
                    'require_password_change' => $definition['require_password_change'],
                    'remarks' => $definition['remarks'],
                    'status' => $definition['status'],
                    'email_verified_at' => now(),
                ]
            );

            $user->additionalRoles()->sync($definition['additional_role_ids']);

            DB::table('permission_user')->where('user_id', $user->id)->delete();

            $rows = collect($definition['allowed'])->map(fn ($code) => ['user_id' => $user->id, 'permission_code' => $code, 'type' => 'allow'])
                ->concat(collect($definition['denied'])->map(fn ($code) => ['user_id' => $user->id, 'permission_code' => $code, 'type' => 'deny']));

            if ($rows->isNotEmpty()) {
                DB::table('permission_user')->insert($rows->all());
            }
        }
    }
}
