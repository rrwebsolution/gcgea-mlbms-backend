<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for both creating and updating a LoanType — rules are
 * identical for store/update, so a single reusable request class is used
 * (see AGENTS notes: "or a single reusable LoanTypeRequest, your call").
 */
class LoanTypeRequest extends FormRequest
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
            'minAmount' => ['required', 'numeric', 'min:0'],
            'maxAmount' => ['required', 'numeric', 'min:0', 'gte:minAmount'],
            'defaultInterestRate' => ['required', 'numeric', 'min:0'],
            'interestMethod' => ['required', Rule::in(['Flat Interest', 'Diminishing Balance', 'Zero Interest', 'Custom'])],
            'processingFee' => ['required', 'numeric', 'min:0'],
            'maxTermMonths' => ['required', 'integer', 'min:1'],
            'requiredMembershipMonths' => ['required', 'integer', 'min:0'],
            'requiredContributionMonths' => ['required', 'integer', 'min:0'],
            'allowExistingActiveLoan' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ];
    }

    /**
     * Maps validated camelCase input to the LoanType model's snake_case columns.
     *
     * @return array<string, mixed>
     */
    public function modelAttributes(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'min_amount' => $data['minAmount'],
            'max_amount' => $data['maxAmount'],
            'default_interest_rate' => $data['defaultInterestRate'],
            'interest_method' => $data['interestMethod'],
            'processing_fee' => $data['processingFee'],
            'max_term_months' => $data['maxTermMonths'],
            'required_membership_months' => $data['requiredMembershipMonths'],
            'required_contribution_months' => $data['requiredContributionMonths'],
            'allow_existing_active_loan' => $data['allowExistingActiveLoan'] ?? false,
            'status' => $data['status'],
        ];
    }
}
