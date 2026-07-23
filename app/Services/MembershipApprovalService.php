<?php

namespace App\Services;

use App\Models\ApprovalAction;
use App\Models\ApprovalInstance;
use App\Models\Member;
use App\Models\MembershipApprovalSetting;
use App\Models\User;
use App\Models\WorkflowDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MembershipApprovalService
{
    public function __construct(
        private readonly ApprovalWorkflowService $workflow,
        private readonly AuditLogService $auditLog,
    ) {}

    public function process(Member $member, User $actor, string $source): void
    {
        $settings = MembershipApprovalSetting::current();
        $requiresApproval = $source === 'import'
            ? $settings->imported_members_require_approval
            : $settings->manual_registration_requires_approval;

        if (! $requiresApproval && (! $settings->auto_approve_requires_permission || $actor->hasPermission('members.auto_approve'))) {
            $this->autoApprove($member, $actor, $source);

            return;
        }

        $this->configureMemberWorkflow($settings, $actor);
        $member->update([
            'membership_status' => 'Pending',
            'registration_status' => 'pending_approval',
            'approval_source' => $source,
            'submitted_at' => now(),
            'approved_at' => null,
            'approved_by_user_id' => null,
        ]);
        $this->workflow->startInstance($member, 'member_registration', $actor);
        $this->auditLog->record($actor, $member, 'member_submitted_for_approval', "Source: {$source}");
    }

    public function assertMayApproveOwn(Member $member, User $actor): void
    {
        $settings = MembershipApprovalSetting::current();
        if ($settings->prevent_self_approval && $member->submitted_by_user_id === $actor->id) {
            $this->auditLog->record($actor, $member, 'self_approval_blocked');
            throw new AuthorizationException('You cannot approve a member registration that you encoded.');
        }
    }

    public function markApproved(Member $member, User $actor): array
    {
        return [
            'membership_status' => 'Active',
            'registration_status' => 'approved',
            'approved_at' => now(),
            'approved_by_user_id' => $actor->id,
        ];
    }

    private function autoApprove(Member $member, User $actor, string $source): void
    {
        DB::transaction(function () use ($member, $actor, $source) {
            $definition = WorkflowDefinition::where('module_key', 'member_registration')->firstOrFail();
            $instance = ApprovalInstance::updateOrCreate(
                ['subject_type' => Member::class, 'subject_id' => $member->id],
                [
                    'workflow_definition_id' => $definition->id,
                    'current_stage_id' => null,
                    'status' => 'approved',
                    'started_at' => now(),
                    'completed_at' => now(),
                ]
            );
            ApprovalAction::create([
                'approval_instance_id' => $instance->id,
                'subject_type' => Member::class,
                'subject_id' => $member->id,
                'action' => 'auto_approved',
                'acted_by_user_id' => $actor->id,
                'remarks' => "Automatically approved from {$source}.",
                'acted_at' => now(),
            ]);
            $member->update([
                'membership_status' => 'Active',
                'registration_status' => 'approved',
                'approval_source' => 'auto_approved',
                'submitted_at' => now(),
                'approved_at' => now(),
                'approved_by_user_id' => $actor->id,
            ]);
            $this->auditLog->record($actor, $member, 'member_auto_approved', "Source: {$source}");
        });
    }

    private function configureMemberWorkflow(MembershipApprovalSetting $settings, User $submitter): void
    {
        $definition = WorkflowDefinition::where('module_key', 'member_registration')->firstOrFail();
        $definition->update(['is_enabled' => true]);

        if ($settings->approver_assignment_type === 'workflow') {
            if ($settings->approval_workflow_id && $settings->approval_workflow_id !== $definition->id) {
                throw new RuntimeException('The selected approval workflow is not the Member Registration workflow.');
            }
            return;
        }

        $stage = $definition->stages()->orderBy('sequence')->firstOrFail();
        if ($settings->approver_assignment_type === 'user') {
            $user = User::whereKey($settings->default_approver_user_id)->where('status', 'Active')->first();
            if ($settings->prevent_self_approval && $user?->id === $submitter->id) {
                $user = User::where('status', 'Active')
                    ->whereKeyNot($submitter->id)
                    ->get()
                    ->first(fn (User $candidate) => $candidate->hasPermission('members.approve'));
            }
            if (! $user || ! $user->hasPermission('members.approve')) {
                throw new RuntimeException('The configured member approver is inactive or lacks members.approve permission.');
            }
            $stage->update([
                'approver_type' => 'user',
                'approver_user_id' => $user->id,
                'approver_role_id' => null,
                'approver_office_id' => null,
            ]);
            return;
        }

        if (! $settings->default_approver_role_id) {
            throw new RuntimeException('Please select an approving role before enabling member approval.');
        }
        $stage->update([
            'approver_type' => 'role',
            'approver_role_id' => $settings->default_approver_role_id,
            'approver_user_id' => null,
            'approver_office_id' => null,
        ]);
    }
}
