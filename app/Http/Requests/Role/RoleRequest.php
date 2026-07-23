<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
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
        $roleId = $this->route('role')?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($roleId)],
            'code' => ['required', 'string', 'max:100', Rule::unique('roles', 'code')->ignore($roleId)],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,code'],
        ];
    }
}
