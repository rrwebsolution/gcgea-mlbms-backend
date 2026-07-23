<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalAction extends Model
{
    protected $fillable = [
        'approval_instance_id',
        'subject_type',
        'subject_id',
        'workflow_stage_id',
        'action',
        'acted_by_user_id',
        'remarks',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ApprovalInstance::class, 'approval_instance_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
