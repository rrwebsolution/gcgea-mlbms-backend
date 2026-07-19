<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmortizationEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'installmentNumber' => $this->installment_number,
            'dueDate' => $this->due_date?->toDateString(),
            'beginningBalance' => (float) $this->beginning_balance,
            'principal' => (float) $this->principal,
            'interest' => (float) $this->interest,
            'penalty' => (float) $this->penalty,
            'amountDue' => (float) $this->amount_due,
            'amountPaid' => (float) $this->amount_paid,
            'remainingBalance' => (float) $this->remaining_balance,
            'status' => $this->status,
        ];
    }
}
