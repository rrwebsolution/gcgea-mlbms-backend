<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeductionTypeRequest extends FormRequest
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
        $deductionType = $this->route('deductionType');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('deduction_types', 'code')->ignore($deductionType?->id),
            ],
            'description' => ['nullable', 'string'],
            'defaultAmount' => ['sometimes', 'numeric', 'min:0'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function modelAttributes(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'default_amount' => $data['defaultAmount'] ?? 200,
            'is_active' => $data['isActive'] ?? true,
            'sort_order' => $data['sortOrder'] ?? 0,
        ];
    }
}
