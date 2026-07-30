<?php

namespace App\Http\Requests\Auth;

use App\Support\SecurityPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
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
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', ...SecurityPolicy::passwordRules()],
            'confirmPassword' => ['required', 'string', 'same:newPassword'],
        ];
    }
}
