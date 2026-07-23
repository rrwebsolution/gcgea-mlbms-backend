<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipApprovalSetting extends Model
{
    protected $fillable = [
        'manual_registration_requires_approval',
        'imported_members_require_approval',
        'approver_assignment_type',
        'default_approver_user_id',
        'default_approver_role_id',
        'approval_workflow_id',
        'prevent_self_approval',
        'auto_approve_requires_permission',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'manual_registration_requires_approval' => 'boolean',
            'imported_members_require_approval' => 'boolean',
            'prevent_self_approval' => 'boolean',
            'auto_approve_requires_permission' => 'boolean',
        ];
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_approver_user_id');
    }

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_approver_role_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'approval_workflow_id');
    }

    public static function current(): self
    {
        return self::firstOrCreate([], [
            'manual_registration_requires_approval' => false,
            'imported_members_require_approval' => false,
            'approver_assignment_type' => 'workflow',
            'prevent_self_approval' => true,
            'auto_approve_requires_permission' => true,
        ]);
    }
}
