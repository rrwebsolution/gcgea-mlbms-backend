<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManualPayrollDeductionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $amounts = $this->details->pluck('amount', 'deduction_code');

        return [
            'id' => (string) $this->id, 'payrollReference' => $this->payroll_reference, 'payrollPeriod' => $this->payroll_period,
            'payrollDate' => $this->payroll_date?->toDateString(), 'officeId' => (string) $this->office_id, 'remarks' => $this->remarks,
            'member' => new MemberResource($this->whenLoaded('member')), 'monthlyDues' => (float) ($amounts['monthly_dues'] ?? 0),
            'cashPabaon' => (float) ($amounts['cash_pabaon'] ?? 0), 'loanDeduction' => (float) ($amounts['loan'] ?? 0),
            'totalDeduction' => (float) $this->total_deduction, 'status' => $this->status,
            'postedBy' => $this->postedBy?->full_name, 'postedAt' => $this->posted_at?->toIso8601String(),
        ];
    }
}
