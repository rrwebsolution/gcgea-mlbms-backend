<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_approval_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('manual_registration_requires_approval')->default(false);
            $table->boolean('imported_members_require_approval')->default(false);
            $table->string('approver_assignment_type')->default('workflow');
            $table->foreignId('default_approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('default_approver_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('approval_workflow_id')->nullable()->constrained('workflow_definitions')->nullOnDelete();
            $table->boolean('prevent_self_approval')->default(true);
            $table->boolean('auto_approve_requires_permission')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('registration_status')->default('approved')->index();
            $table->string('approval_source')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });

        DB::table('membership_approval_settings')->insert([
            'manual_registration_requires_approval' => false,
            'imported_members_require_approval' => false,
            'approver_assignment_type' => 'workflow',
            'approval_workflow_id' => DB::table('workflow_definitions')->where('module_key', 'member_registration')->value('id'),
            'prevent_self_approval' => true,
            'auto_approve_requires_permission' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->updateOrInsert(
            ['code' => 'members.auto_approve'],
            ['label' => 'Auto Approve Members', 'group' => 'members', 'description' => 'Automatically approve new and imported members', 'created_at' => now(), 'updated_at' => now()]
        );
        $roleIds = DB::table('roles')->whereIn('code', ['super_administrator', 'membership_officer'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_code' => 'members.auto_approve']);
        }
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['registration_status', 'approval_source', 'submitted_by_user_id', 'submitted_at', 'approved_by_user_id', 'approved_at']);
        });
        Schema::dropIfExists('membership_approval_settings');
        DB::table('permission_role')->where('permission_code', 'members.auto_approve')->delete();
        DB::table('permissions')->where('code', 'members.auto_approve')->delete();
    }
};
