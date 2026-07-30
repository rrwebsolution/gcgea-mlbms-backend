<?php

namespace App\Http\Requests\Auth;

use App\Support\SecurityPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', ...SecurityPolicy::passwordRules()],
            'confirmPassword' => ['required', 'string', 'same:password'],
        ];
    }
}
