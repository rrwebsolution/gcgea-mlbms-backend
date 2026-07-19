<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Empty strings aren't treated as "absent" by Laravel's `nullable` rule —
     * without this, a blank draft field (e.g. an untouched date input) would
     * still fail rules like `exists:offices,id`. Only applies to drafts;
     * final-submit payloads are expected to be fully populated already.
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
        $memberId = $this->route('member')?->id;
        $phRegex = '/^09\d{9}$/';
        $asDraft = $this->boolean('asDraft');
        // A draft only needs enough to identify the record; every other field
        // stays optional (but still type/format-checked when present) until
        // final submission via MemberController::submit().
        $req = $asDraft ? 'nullable' : 'required';

        return [
            'asDraft' => ['boolean'],
            'employeeNumber' => [$req, 'string', 'max:50'],
            'surname' => [$asDraft ? 'required_without:firstName' : 'required', 'string', 'max:100'],
            'firstName' => [$asDraft ? 'required_without:surname' : 'required', 'string', 'max:100'],
            'middleName' => ['nullable', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'sex' => [$req, Rule::in(['Male', 'Female'])],
            'birthdate' => [$req, 'date'],
            'civilStatus' => [$req, Rule::in(['Single', 'Married', 'Widowed', 'Separated', 'Divorced'])],
            'permanentAddress' => [$req, 'string'],
            'cellphoneNumber' => [$asDraft ? 'nullable' : 'required', 'string', 'regex:'.$phRegex],
            'email' => ['nullable', 'email', Rule::unique('members', 'email')->ignore($memberId)],
            'nameOfSpouse' => ['nullable', 'string', 'max:255'],

            'officeId' => [$req, 'exists:offices,id'],
            'position' => [$req, 'string', 'max:255'],
            'dateOfRegularAppointment' => [$req, 'date'],
            'employmentStatus' => [$req, Rule::in(['Permanent', 'Casual', 'Job Order', 'Contractual', 'Co-terminus'])],

            'membershipType' => [$req, Rule::in(['Regular', 'Associate', 'Honorary'])],
            'membershipDate' => [$req, 'date'],
            'membershipStatus' => [$req, Rule::in(['Active', 'Inactive', 'Suspended', 'Terminated', 'Deceased'])],
            'retireeStatus' => [$req, Rule::in(['Not Retired', 'Retired'])],
            'remarks' => ['nullable', 'string'],

            'beneficiaries' => ['array'],
            'beneficiaries.*.id' => ['nullable'],
            'beneficiaries.*.fullName' => [$req, 'string', 'max:255'],
            'beneficiaries.*.relationship' => [$req, 'string', 'max:100'],
            'beneficiaries.*.birthdate' => [$req, 'date'],
            'beneficiaries.*.contactNumber' => ['nullable', 'string', 'regex:'.$phRegex],
            'beneficiaries.*.address' => ['nullable', 'string'],
            'beneficiaries.*.sharePercentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
