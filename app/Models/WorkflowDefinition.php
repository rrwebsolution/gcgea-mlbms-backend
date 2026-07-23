<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowDefinition extends Model
{
    protected $fillable = [
        'module_key',
        'label',
        'subject_model',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowStage::class)->orderBy('sequence');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(ApprovalInstance::class);
    }
}
