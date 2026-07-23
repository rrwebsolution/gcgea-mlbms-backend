<?php

namespace App\Repositories;

use App\Models\PayrollDeductionHeader;

class PayrollDeductionRepository
{
    public function createDraft(array $header, array $amounts): PayrollDeductionHeader
    {
        $payroll = PayrollDeductionHeader::create($header);
        foreach ($amounts as $code => $amount) {
            $payroll->details()->create(['deduction_code' => $code, 'amount' => $amount]);
        }

        return $payroll->load(['member.office', 'details', 'postedBy']);
    }
}
