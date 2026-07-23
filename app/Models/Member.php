<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Member extends Model
{
    protected $fillable = [
        'member_number',
        'employee_number',
        'surname',
        'first_name',
        'middle_name',
        'suffix',
        'sex',
        'birthdate',
        'civil_status',
        'permanent_address',
        'cellphone_number',
        'email',
        'name_of_spouse',
        'profile_photo_url',
        'office_id',
        'position',
        'date_of_regular_appointment',
        'employment_status',
        'membership_type',
        'membership_date',
        'membership_status',
        'net_pay',
        'retiree_status',
        'remarks',
        'is_archived',
        'archived_at',
        'archived_reason',
        'created_by',
        'imported_from_batch_id',
        'is_draft',
        'draft_reference_no',
        'draft_completion_percentage',
        'draft_current_step',
        'registration_status',
        'approval_source',
        'submitted_by_user_id',
        'submitted_at',
        'approved_by_user_id',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'date_of_regular_appointment' => 'date',
            'membership_date' => 'date',
            'net_pay' => 'decimal:2',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
            'is_draft' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Section-by-section completion used for draft_completion_percentage —
     * mirrors the wizard's 5 sections in MemberRegistrationPage.tsx.
     */
    public function draftCompletionPercentage(): int
    {
        $sections = [
            (bool) $this->surname && (bool) $this->first_name && (bool) $this->birthdate,
            (bool) $this->permanent_address,
            (bool) $this->office_id && (bool) $this->position,
            (bool) $this->membership_type && (bool) $this->membership_date,
            $this->relationLoaded('beneficiaries') ? $this->beneficiaries->isNotEmpty() : $this->beneficiaries()->exists(),
        ];

        $complete = count(array_filter($sections));

        return (int) round(($complete / count($sections)) * 100);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class);
    }

    public function approvalInstance(): MorphOne
    {
        return $this->morphOne(ApprovalInstance::class, 'subject');
    }

    public function approvalActions(): MorphMany
    {
        return $this->morphMany(ApprovalAction::class, 'subject')->orderBy('acted_at');
    }

    public function getFullNameAttribute(): string
    {
        $suffixPart = $this->suffix ? " {$this->suffix}" : '';
        $middlePart = $this->middle_name ? " {$this->middle_name}" : '';

        return "{$this->surname}{$suffixPart}, {$this->first_name}{$middlePart}";
    }

    /**
     * Mirrors src/services/members.service.ts isProfileComplete().
     */
    public function isProfileComplete(): bool
    {
        return (bool) $this->email
            && (bool) $this->cellphone_number
            && (bool) $this->permanent_address
            && $this->beneficiaries()->exists()
            && $this->documents()->exists();
    }

    /**
     * Mirrors src/services/members.service.ts profileCompleteness().
     */
    public function profileCompleteness(): int
    {
        $checks = [
            (bool) $this->email,
            (bool) $this->cellphone_number,
            (bool) $this->permanent_address,
            $this->civil_status !== 'Married' || (bool) $this->name_of_spouse,
            $this->beneficiaries()->exists(),
            $this->documents()->exists(),
            (bool) $this->profile_photo_url,
        ];

        $complete = count(array_filter($checks));

        return (int) round(($complete / count($checks)) * 100);
    }
}
