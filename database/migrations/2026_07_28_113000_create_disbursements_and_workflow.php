<?php

use App\Models\Disbursement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->foreignId('annual_budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('annual_budget_item_id')->constrained()->restrictOnDelete();
            $table->date('disbursement_date');
            $table->string('payee');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method');
            $table->string('payment_reference')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->default('Draft');
            $table->string('prepared_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['disbursement_date', 'status']);
        });

        $permissions = [
            ['disbursements.view', 'View Disbursements', 'View disbursement records and reports'],
            ['disbursements.manage', 'Manage Disbursements', 'Create and edit disbursement drafts'],
            ['disbursements.submit', 'Submit Disbursements', 'Submit disbursements for approval'],
            ['disbursements.approve', 'Approve Disbursements', 'Approve, reject, or return disbursements'],
            ['disbursements.pay', 'Pay Disbursements', 'Mark approved disbursements as paid'],
            ['disbursements.void', 'Void Disbursements', 'Void paid disbursements with a reason'],
        ];
        foreach ($permissions as [$code, $label, $description]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['label' => $label, 'group' => 'disbursements', 'description' => $description, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $rolePermissions = [
            'super_administrator' => array_column($permissions, 0),
            'treasurer' => ['disbursements.view', 'disbursements.manage', 'disbursements.submit', 'disbursements.pay', 'disbursements.void'],
            'approving_officer' => ['disbursements.view', 'disbursements.approve'],
            'auditor_viewer' => ['disbursements.view'],
            'regional_auditor' => ['disbursements.view'],
        ];
        foreach ($rolePermissions as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if (! $roleId) continue;
            foreach ($codes as $code) {
                DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_code' => $code]);
            }
        }

        DB::table('workflow_definitions')->updateOrInsert(
            ['module_key' => 'disbursement'],
            [
                'label' => 'Disbursement',
                'subject_model' => Disbursement::class,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $definitionId = DB::table('workflow_definitions')->where('module_key', 'disbursement')->value('id');
        DB::table('workflow_stages')->insert([
            'workflow_definition_id' => $definitionId,
            'sequence' => 1,
            'code' => 'approve',
            'label' => 'Approving Officer / President Approval',
            'stage_type' => 'approve',
            'approver_type' => 'role',
            'approver_role_id' => DB::table('roles')->where('code', 'approving_officer')->value('id'),
            'required_permission_code' => 'disbursements.approve',
            'is_final' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $definitionId = DB::table('workflow_definitions')->where('module_key', 'disbursement')->value('id');
        if ($definitionId) {
            DB::table('workflow_stages')->where('workflow_definition_id', $definitionId)->delete();
            DB::table('workflow_definitions')->where('id', $definitionId)->delete();
        }
        $codes = ['disbursements.view', 'disbursements.manage', 'disbursements.submit', 'disbursements.approve', 'disbursements.pay', 'disbursements.void'];
        DB::table('permission_role')->whereIn('permission_code', $codes)->delete();
        DB::table('permissions')->whereIn('code', $codes)->delete();
        Schema::dropIfExists('disbursements');
    }
};
