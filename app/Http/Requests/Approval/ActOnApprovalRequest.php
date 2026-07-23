<?php

namespace App\Http\Requests\Approval;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActOnApprovalRequest extends FormRequest
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
            'action' => ['required', Rule::in(['review', 'approve', 'reject', 'return', 'release'])],
            'remarks' => [
                Rule::requiredIf(in_array($this->input('action'), ['reject', 'return'], true)),
                'nullable', 'string',
            ],
            'extra' => ['array'],
        ];
    }
}
