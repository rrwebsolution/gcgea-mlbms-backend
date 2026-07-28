<?php

namespace App\Http\Requests;

use App\Models\DeductionType;
use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkPayrollDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payroll.bulk.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'payrollReference' => ['required', 'string', 'max:255', 'unique:payroll_deduction_headers,payroll_reference'],
            'payrollPeriod' => ['required', 'date_format:Y-m'],
            'payrollDate' => ['required', 'date'],
            'officeId' => ['required', 'integer', 'exists:offices,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.memberId' => [
                'required', 'distinct', 'integer',
                Rule::exists('members', 'id')->where(fn ($query) => $query->where('membership_status', 'Active')->where('is_draft', false)->where('is_archived', false)),
                Rule::unique('payroll_deduction_headers', 'member_id')->where(fn ($query) => $query->where('payroll_period', $this->input('payrollPeriod'))),
            ],
            'rows.*.monthlyDues' => ['required', 'numeric', 'min:0'],
            'rows.*.cashPabaon' => ['required', 'numeric', 'min:0'],
            'rows.*.loanDeduction' => ['required', 'numeric', 'min:0'],
            'rows.*.loanableAmount' => ['nullable', 'numeric', 'min:0'],
            'rows.*.outstandingInterest' => ['nullable', 'numeric', 'min:0'],
            'rows.*.outstandingBalance' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $rows = $this->input('rows', []);
            if (! is_array($rows)) return;

            $canOverride = $this->user()?->hasPermission('payroll.bulk.override') ?? false;
            $defaultPabaon = (float) (DeductionType::query()->where('code', 'pabaon')->value('default_amount') ?? 200);
            $memberIds = array_column($rows, 'memberId');
            $loans = Loan::query()
                ->whereIn('member_id', $memberIds)
                ->whereIn('status', ['Released', 'Active', 'Overdue', 'Restructured'])
                ->get(['id', 'member_id', 'outstanding_balance'])
                ->groupBy('member_id')
                ->map(fn ($group) => $group->sortByDesc('id')->first());

            foreach ($rows as $index => $row) {
                if (! $canOverride && ((float) ($row['monthlyDues'] ?? 0) !== 100.0 || (float) ($row['cashPabaon'] ?? 0) !== $defaultPabaon)) {
                    $validator->errors()->add("rows.{$index}.monthlyDues", 'Override permission is required to change the configured deduction amounts.');
                }

                $loanDeduction = (float) ($row['loanDeduction'] ?? 0);
                if ($loanDeduction > 0) {
                    $loan = $loans->get($row['memberId'] ?? null);
                    if ($loan && $loanDeduction > (float) $loan->outstanding_balance) {
                        $validator->errors()->add("rows.{$index}.loanDeduction", 'Loan deduction must not exceed the remaining loan balance.');
                    }
                }
            }
        }];
    }
}
