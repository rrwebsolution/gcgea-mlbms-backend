<?php

namespace App\Http\Requests\WorkflowDefinition;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.sequence' => ['required', 'integer', 'min:1'],
            'stages.*.code' => ['required', 'string', 'max:50'],
            'stages.*.label' => ['required', 'string', 'max:150'],
            'stages.*.stageType' => ['required', Rule::in(['review', 'approve', 'release'])],
            'stages.*.approverType' => ['required', Rule::in(['role', 'user', 'office'])],
            'stages.*.approverRoleId' => ['nullable', 'required_if:stages.*.approverType,role', 'exists:roles,id'],
            'stages.*.approverUserId' => ['nullable', 'required_if:stages.*.approverType,user', 'exists:users,id'],
            'stages.*.approverOfficeId' => ['nullable', 'required_if:stages.*.approverType,office', 'exists:offices,id'],
            'stages.*.requiredPermissionCode' => ['nullable', 'string', 'exists:permissions,code'],
            'stages.*.isFinal' => ['boolean'],
        ];
    }
}
