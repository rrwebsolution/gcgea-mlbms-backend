<?php

namespace App\Http\Requests;

use App\Models\Loan;
use App\Models\Member;
use App\Models\DeductionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ManualPayrollDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payroll.manual.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'payrollReference' => ['required', 'string', 'max:255', 'unique:payroll_deduction_headers,payroll_reference'],
            'payrollPeriod' => ['required', 'date_format:Y-m'],
            'payrollDate' => ['required', 'date'],
            'officeId' => ['required', 'integer', 'exists:offices,id'],
            'memberId' => ['required', 'integer', Rule::exists('members', 'id')->where(fn ($query) => $query->where('membership_status', 'Active')->where('is_draft', false)->where('is_archived', false))],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'monthlyDues' => ['required', 'numeric', 'min:0'],
            'cashPabaon' => ['required', 'numeric', 'min:0'],
            'loanDeduction' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $member = Member::with('approvalInstance')->find($this->integer('memberId'));
            if ($member && $member->approvalInstance?->status !== 'approved') {
                $validator->errors()->add('memberId', 'Only approved members may receive payroll deductions.');
            }
            $defaultPabaon = (float) (DeductionType::query()->where('code', 'pabaon')->value('default_amount') ?? 200);
            if (! $this->user()?->hasPermission('payroll.manual.override') && ((float) $this->input('monthlyDues') !== 100.0 || (float) $this->input('cashPabaon') !== $defaultPabaon)) {
                $validator->errors()->add('monthlyDues', 'Override permission is required to change the configured deduction amounts.');
            }
            $loanDeduction = (float) $this->input('loanDeduction');
            if ($loanDeduction > 0) {
                $loan = Loan::query()->where('member_id', $this->integer('memberId'))->whereIn('status', ['Released', 'Active', 'Overdue', 'Restructured'])->latest()->first();
                if (! $loan) {
                    $validator->errors()->add('loanDeduction', 'Loan deduction must be zero when the member has no active loan.');
                } elseif ($loanDeduction > (float) $loan->outstanding_balance) {
                    $validator->errors()->add('loanDeduction', 'Loan deduction must not exceed the remaining loan balance.');
                }
            }
        }];
    }
}
