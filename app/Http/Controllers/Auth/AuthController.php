<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource|JsonResponse
    {
        $login = $request->string('usernameOrEmail')->toString();

        $user = User::where('username', $login)->orWhere('email', $login)->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid username/email or password.'], 422);
        }

        if ($user->status !== 'Active') {
            return response()->json(['message' => 'This account has been disabled. Please contact your system administrator.'], 422);
        }

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json(['message' => 'Invalid username/email or password.'], 422);
        }

        Auth::login($user, $request->boolean('rememberMe'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        LoginHistory::create([
            'user_id' => $user->id,
            'login_at' => now(),
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'status' => 'Success',
        ]);

        return new UserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        // Always respond with a generic success message regardless of whether the
        // email exists, to avoid leaking account existence — matches the frontend's
        // existing "if an account exists..." copy on the confirmation screen.
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If an account exists with that email address, reset instructions have been sent.']);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->string('currentPassword')->toString(), $user->password)) {
            return response()->json(['message' => 'Your current password is incorrect.'], 422);
        }

        $user->forceFill(['password' => Hash::make($request->string('newPassword')->toString())])->save();

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
