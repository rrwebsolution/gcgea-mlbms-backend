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
        // Null on create (no {member} route param yet), the existing Member on update.
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
            // Enforced only when adding a new member. Skipped on update — a lot
            // of existing members already legitimately share an email (import
            // data, household members), so re-saving an already-duplicated
            // email on an edit must not fail; only a brand-new duplicate should.
            'email' => $memberId
                ? ['nullable', 'email']
                : ['nullable', 'email', Rule::unique('members', 'email')],
            'nameOfSpouse' => ['nullable', 'string', 'max:255'],

            'officeId' => [$req, 'exists:offices,id'],
            'position' => [$req, 'string', 'max:255'],
            'dateOfRegularAppointment' => [$req, 'date'],
            'employmentStatus' => [
                $req,
                Rule::exists('employment_statuses', 'name')->where(function ($query) use ($memberId) {
                    $query->where('is_active', true);
                    if ($memberId) {
                        $currentStatus = $this->route('member')?->employment_status;
                        if ($currentStatus) {
                            $query->orWhere('name', $currentStatus);
                        }
                    }
                }),
            ],

            'membershipType' => [$req, Rule::in(['Regular', 'Associate', 'Honorary'])],
            'membershipDate' => [$req, 'date'],
            // "Pending" is set only by the member-import pipeline (never by
            // this request, which the import bypasses entirely) and flipped
            // to "Active" on approval — kept in the shared vocabulary so the
            // manual edit form doesn't reject an already-imported member.
            'membershipStatus' => [$req, Rule::in(['Pending', 'Active', 'Inactive', 'Suspended', 'Terminated', 'Deceased'])],
            'netPay' => ['nullable', 'numeric', 'min:0'],
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
