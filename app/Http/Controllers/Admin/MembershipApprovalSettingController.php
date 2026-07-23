<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipApprovalSetting;
use App\Models\User;
use App\Models\WorkflowDefinition;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MembershipApprovalSettingController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->hasPermission('settings.view'), 403);

        return response()->json($this->payload(MembershipApprovalSetting::current()));
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->hasPermission('settings.update'), 403);
        $data = $request->validate([
            'manualRegistrationRequiresApproval' => ['required', 'boolean'],
            'importedMembersRequireApproval' => ['required', 'boolean'],
            'approverAssignmentType' => ['required', Rule::in(['user', 'role', 'workflow'])],
            'defaultApproverUserId' => ['nullable', 'integer', 'exists:users,id'],
            'defaultApproverRoleId' => ['nullable', 'integer', 'exists:roles,id'],
            'approvalWorkflowId' => ['nullable', 'integer', 'exists:workflow_definitions,id'],
            'preventSelfApproval' => ['required', 'boolean'],
            'autoApproveRequiresPermission' => ['required', 'boolean'],
        ]);

        $requiresApproval = $data['manualRegistrationRequiresApproval'] || $data['importedMembersRequireApproval'];
        if ($requiresApproval) {
            if ($data['approverAssignmentType'] === 'user') {
                $approver = User::whereKey($data['defaultApproverUserId'])->where('status', 'Active')->first();
                abort_unless($approver && $approver->hasPermission('members.approve'), 422, 'Please select an active user with member approval permission.');
            }
            if ($data['approverAssignmentType'] === 'role') {
                abort_unless($data['defaultApproverRoleId'], 422, 'Please select an approving role.');
            }
            if ($data['approverAssignmentType'] === 'workflow') {
                $workflow = WorkflowDefinition::whereKey($data['approvalWorkflowId'])->where('is_enabled', true)->first();
                abort_unless($workflow, 422, 'Please select an enabled approval workflow.');
            }
        }

        $setting = MembershipApprovalSetting::current();
        $setting->update([
            'manual_registration_requires_approval' => $data['manualRegistrationRequiresApproval'],
            'imported_members_require_approval' => $data['importedMembersRequireApproval'],
            'approver_assignment_type' => $data['approverAssignmentType'],
            'default_approver_user_id' => $data['approverAssignmentType'] === 'user' ? $data['defaultApproverUserId'] : null,
            'default_approver_role_id' => $data['approverAssignmentType'] === 'role' ? $data['defaultApproverRoleId'] : null,
            'approval_workflow_id' => $data['approverAssignmentType'] === 'workflow' ? $data['approvalWorkflowId'] : null,
            'prevent_self_approval' => $data['preventSelfApproval'],
            'auto_approve_requires_permission' => $data['autoApproveRequiresPermission'],
            'updated_by' => $request->user()->id,
            'created_by' => $setting->created_by ?? $request->user()->id,
        ]);

        return response()->json($this->payload($setting->fresh()));
    }

    private function payload(MembershipApprovalSetting $setting): array
    {
        return [
            'id' => (string) $setting->id,
            'manualRegistrationRequiresApproval' => $setting->manual_registration_requires_approval,
            'importedMembersRequireApproval' => $setting->imported_members_require_approval,
            'approverAssignmentType' => $setting->approver_assignment_type,
            'defaultApproverUserId' => $setting->default_approver_user_id ? (string) $setting->default_approver_user_id : null,
            'defaultApproverRoleId' => $setting->default_approver_role_id ? (string) $setting->default_approver_role_id : null,
            'approvalWorkflowId' => $setting->approval_workflow_id ? (string) $setting->approval_workflow_id : null,
            'preventSelfApproval' => $setting->prevent_self_approval,
            'autoApproveRequiresPermission' => $setting->auto_approve_requires_permission,
        ];
    }
}
