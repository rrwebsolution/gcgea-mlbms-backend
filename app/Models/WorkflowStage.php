<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStage extends Model
{
    protected $fillable = [
        'workflow_definition_id',
        'sequence',
        'code',
        'label',
        'stage_type',
        'approver_type',
        'approver_role_id',
        'approver_user_id',
        'approver_office_id',
        'required_permission_code',
        'is_final',
    ];

    protected function casts(): array
    {
        return [
            'is_final' => 'boolean',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approver_role_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function approverOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'approver_office_id');
    }
}
