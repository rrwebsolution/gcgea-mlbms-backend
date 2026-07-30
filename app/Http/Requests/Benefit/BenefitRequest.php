<?php

namespace App\Http\Requests\Benefit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BenefitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Empty strings from partially-filled wizard steps shouldn't fail
     * `nullable` rules like `exists:benefit_types,id` — only relevant to drafts.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->boolean('asDraft')) {
            return;
        }

        $this->merge(
            collect($this->all())
                ->map(fn ($value) => $value === '' ? null : $value)
                ->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $asDraft = $this->boolean('asDraft');
        $req = $asDraft ? 'nullable' : 'required';

        return [
            'asDraft' => ['boolean'],
            'memberId' => ['required', 'exists:members,id'],
            'benefitTypeId' => [$req, 'exists:benefit_types,id'],
            'requestedAmount' => [$req, 'numeric', 'gt:0'],
            'reason' => [$req, 'string'],
            'incidentDate' => ['nullable', 'date'],
            'beneficiaryOrRecipient' => [$req, 'string'],
            'requirements' => ['array'],
            'requirements.*.label' => ['required_with:requirements', 'string'],
            'requirements.*.completed' => ['boolean'],
            'overrideEligibility' => ['sometimes', 'boolean'],
            'overrideReason' => ['nullable', 'required_if:overrideEligibility,true', 'string', 'max:1000'],
            'draftCurrentStep' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
