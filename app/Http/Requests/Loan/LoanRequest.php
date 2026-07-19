<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Empty strings from partially-filled wizard steps shouldn't fail
     * `nullable` rules like `exists:loan_types,id` — only relevant to drafts.
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
            'loanTypeId' => [$req, 'exists:loan_types,id'],
            'requestedAmount' => [$req, 'numeric', 'gt:0'],
            'termMonths' => [$req, 'integer', 'min:1'],
            'purpose' => [$req, 'string'],
            'paymentMethod' => [$req, Rule::in(['Payroll Deduction', 'Cash', 'Bank Transfer', 'Check'])],
            'requirements' => ['array'],
            'requirements.*.label' => ['required_with:requirements', 'string'],
            'requirements.*.completed' => ['boolean'],
            'draftCurrentStep' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
