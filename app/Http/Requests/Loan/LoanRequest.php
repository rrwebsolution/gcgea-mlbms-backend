<?php

namespace App\Http\Requests\Loan;

use App\Models\User;
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
        $reloanReq = ($asDraft || $this->route('loan')?->application_type !== 'reloan') ? 'nullable' : 'required';

        return [
            'asDraft' => ['boolean'],
            'memberId' => ['required', 'exists:members,id'],
            'loanTypeId' => [$req, 'exists:loan_types,id'],
            'requestedAmount' => [$req, 'numeric', 'gt:0'],
            'termMonths' => [$req, 'integer', 'min:1'],
            'purpose' => [$req, 'string'],
            'paymentMethod' => [$req, Rule::in(['Payroll Deduction', 'Cash', 'Bank Transfer', 'Check'])],
            'assignedOfficer' => [
                $asDraft ? 'nullable' : 'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! filled($value)) return;

                    $qualified = User::query()
                        ->where('full_name', $value)
                        ->where('status', 'Active')
                        ->where(function ($query) {
                            $roleNames = ['Loan Officer', 'Senior Loan Officer', 'Assigned Loan Officer'];
                            $query->whereHas('role', fn ($role) => $role->whereIn('name', $roleNames))
                                ->orWhereHas('additionalRoles', fn ($role) => $role->whereIn('name', $roleNames));
                        })
                        ->exists();

                    if (! $qualified) {
                        $fail('Please select an active user with a Loan Officer role.');
                    }
                },
            ],
            'requirements' => ['array'],
            'requirements.*.label' => ['required_with:requirements', 'string'],
            'requirements.*.completed' => ['boolean'],
            'draftCurrentStep' => ['nullable', 'integer', 'min:1'],
            'currentNetTakeHomePay' => [$reloanReq, 'numeric', 'gt:0'],
            'eligibilityOverridden' => ['boolean'],
            'eligibilityOverrideReason' => ['nullable', 'string'],
            'boardResolutionReference' => ['nullable', 'string'],
            'boardResolutionDocumentPath' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('asDraft')) return;

            $requirements = collect($this->input('requirements', []));
            if ($requirements->isEmpty() || $requirements->contains(fn ($item) => ! ($item['completed'] ?? false))) {
                $validator->errors()->add(
                    'requirements',
                    'Complete all required loan requirements before submitting. Supporting file uploads are optional.'
                );
            }
        });
    }
}
