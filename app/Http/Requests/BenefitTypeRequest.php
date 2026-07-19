<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for both creating and updating a BenefitType — rules are
 * identical for store/update, so a single reusable request class is used
 * (see AGENTS notes: "or a single reusable ... your call").
 */
class BenefitTypeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'defaultAmount' => ['required', 'numeric', 'min:0'],
            'maximumAmount' => ['required', 'numeric', 'min:0', 'gte:defaultAmount'],
            'eligibilityRequirements' => ['nullable', 'string'],
            'requiredMembershipMonths' => ['required', 'integer', 'min:0'],
            'frequencyLimit' => ['nullable', 'string', 'max:255'],
            'requiredDocuments' => ['sometimes', 'array'],
            'requiredDocuments.*' => ['string'],
            'approvalRequired' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ];
    }

    /**
     * Maps validated camelCase input to the BenefitType model's snake_case columns.
     *
     * @return array<string, mixed>
     */
    public function modelAttributes(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'default_amount' => $data['defaultAmount'],
            'maximum_amount' => $data['maximumAmount'],
            'eligibility_requirements' => $data['eligibilityRequirements'] ?? '',
            'required_membership_months' => $data['requiredMembershipMonths'],
            'frequency_limit' => $data['frequencyLimit'] ?? '',
            'required_documents' => $data['requiredDocuments'] ?? [],
            'approval_required' => $data['approvalRequired'] ?? true,
            'status' => $data['status'],
        ];
    }
}
