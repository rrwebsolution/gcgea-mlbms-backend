<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
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
        $userId = $this->route('user')?->id;

        return [
            'fullName' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($userId)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'contactNumber' => ['nullable', 'string', 'max:20'],
            'password' => [$userId ? 'nullable' : 'required', 'string', 'min:8'],
            'roleId' => ['required', 'exists:roles,id'],
            'additionalRoleIds' => ['array'],
            'additionalRoleIds.*' => ['exists:roles,id'],
            'status' => ['required', Rule::in(['Active', 'Inactive', 'Disabled'])],
            'requirePasswordChange' => ['boolean'],
            'allowedPermissions' => ['array'],
            'allowedPermissions.*' => ['string', 'exists:permissions,code'],
            'deniedPermissions' => ['array'],
            'deniedPermissions.*' => ['string', 'exists:permissions,code'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
