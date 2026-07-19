<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BeneficiaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'memberId' => (string) $this->member_id,
            'fullName' => $this->full_name,
            'relationship' => $this->relationship,
            'birthdate' => $this->birthdate?->toDateString(),
            'contactNumber' => $this->contact_number,
            'address' => $this->address,
            'sharePercentage' => $this->share_percentage !== null ? (float) $this->share_percentage : null,
        ];
    }
}
