<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

class LoanReleaseRequest extends FormRequest
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
        return [
            'releaseReferenceNumber' => ['required', 'string', 'max:100'],
            'releaseMethod' => ['required', 'string', 'max:100'],
            'actualReleasedAmount' => ['required', 'numeric', 'gt:0'],
            'releaseRemarks' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
