<?php

use App\Models\AnnualBudget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_budgets', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('approved_at');
        });

        $permissions = [
            ['annual_budgets.view', 'View Annual Budgets', 'View annual budget lists and details'],
            ['annual_budgets.manage', 'Manage Annual Budgets', 'Create and edit annual budget drafts'],
            ['annual_budgets.submit', 'Submit Annual Budgets', 'Submit annual budgets for approval'],
            ['annual_budgets.approve', 'Approve Annual Budgets', 'Approve, reject, or return annual budgets'],
        ];
        foreach ($permissions as [$code, $label, $description]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['label' => $label, 'group' => 'annual_budgets', 'description' => $description, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $rolePermissions = [
            'super_administrator' => array_column($permissions, 0),
            'treasurer' => ['annual_budgets.view', 'annual_budgets.manage', 'annual_budgets.submit'],
            'approving_officer' => ['annual_budgets.view', 'annual_budgets.approve'],
            'auditor_viewer' => ['annual_budgets.view'],
            'regional_auditor' => ['annual_budgets.view'],
        ];
        foreach ($rolePermissions as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if (! $roleId) continue;
            foreach ($codes as $code) {
                DB::table('permission_role')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_code' => $code,
                ]);
            }
        }

        DB::table('workflow_definitions')->updateOrInsert([
            'module_key' => 'annual_budget',
        ], [
            'label' => 'Annual Budget',
            'subject_model' => AnnualBudget::class,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $definitionId = DB::table('workflow_definitions')->where('module_key', 'annual_budget')->value('id');
        $approverRoleId = DB::table('roles')->where('code', 'approving_officer')->value('id');
        DB::table('workflow_stages')->insert([
            'workflow_definition_id' => $definitionId,
            'sequence' => 1,
            'code' => 'approve',
            'label' => 'Approving Officer / President Approval',
            'stage_type' => 'approve',
            'approver_type' => 'role',
            'approver_role_id' => $approverRoleId,
            'required_permission_code' => 'annual_budgets.approve',
            'is_final' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $definitionId = DB::table('workflow_definitions')->where('module_key', 'annual_budget')->value('id');
        if ($definitionId) {
            DB::table('workflow_stages')->where('workflow_definition_id', $definitionId)->delete();
            DB::table('workflow_definitions')->where('id', $definitionId)->delete();
        }
        DB::table('permission_role')->whereIn('permission_code', [
            'annual_budgets.view', 'annual_budgets.manage', 'annual_budgets.submit', 'annual_budgets.approve',
        ])->delete();
        DB::table('permissions')->whereIn('code', [
            'annual_budgets.view', 'annual_budgets.manage', 'annual_budgets.submit', 'annual_budgets.approve',
        ])->delete();
        Schema::table('annual_budgets', fn (Blueprint $table) => $table->dropColumn('rejection_reason'));
    }
};
