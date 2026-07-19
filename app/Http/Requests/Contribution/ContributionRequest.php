<?php

namespace App\Http\Requests\Contribution;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContributionRequest extends FormRequest
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
        $rules = [
            'contributionPeriod' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'paymentDate' => ['required', 'date'],
            'paymentMethod' => ['required', Rule::in(['Payroll Deduction', 'Cash', 'Bank Transfer', 'Check'])],
            'officialReceiptNumber' => ['nullable', 'string'],
            'payrollReference' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ];

        // memberId is only settable on create — a contribution's member never changes on update.
        if (! $this->route('contribution')) {
            $rules['memberId'] = ['required', 'exists:members,id'];
        }

        return $rules;
    }
}
