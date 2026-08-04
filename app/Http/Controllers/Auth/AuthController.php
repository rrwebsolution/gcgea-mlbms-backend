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
use App\Support\SecurityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource|JsonResponse
    {
        $login = $request->string('usernameOrEmail')->toString();
        $security = SecurityPolicy::settings();
        $throttleKey = Str::lower($login).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, $security['maximumLoginAttempts'])) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => 'Too many failed login attempts. Try again in '.max(1, (int) ceil($seconds / 60)).' minute(s).',
            ], 429);
        }

        $user = User::where('username', $login)->orWhere('email', $login)->first();

        if (! $user) {
            RateLimiter::hit($throttleKey, $security['lockoutDurationMinutes'] * 60);
            $credentialLabel = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email address' : 'username';

            return response()->json([
                'message' => "No account was found for that {$credentialLabel}.",
                'errors' => [
                    'usernameOrEmail' => ["No account was found for that {$credentialLabel}."],
                ],
            ], 422);
        }

        if ($user->status !== 'Active') {
            return response()->json([
                'message' => 'This account has been disabled. Please contact your system administrator.',
                'errors' => [
                    'usernameOrEmail' => ['This account has been disabled. Please contact your system administrator.'],
                ],
            ], 422);
        }

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($throttleKey, $security['lockoutDurationMinutes'] * 60);
            LoginHistory::create([
                'user_id' => $user->id,
                'login_at' => now(),
                'ip_address' => $request->ip(),
                'device' => $request->userAgent(),
                'status' => 'Failed',
            ]);

            return response()->json([
                'message' => 'The password you entered is incorrect.',
                'errors' => [
                    'password' => ['The password you entered is incorrect.'],
                ],
            ], 422);
        }

        Auth::login($user, $request->boolean('rememberMe'));
        RateLimiter::clear($throttleKey);
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

    /**
     * Session-restore check the frontend calls on every page load, including while logged
     * out (e.g. sitting on the login page) — so a guest is the normal case, not an error.
     * Answers 200+null rather than a 401 for that case; see the route definition for why.
     */
    public function me(Request $request): UserResource|JsonResponse
    {
        $user = $request->user();

        // Not response()->json(null) — Symfony's JsonResponse null-coalesces a null $data to
        // an empty ArrayObject before encoding, so the body would silently come out as "{}"
        // instead of a value the frontend can tell apart from a real (if sparse) user object.
        return $user ? new UserResource($user) : response()->json(false);
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
                $user->forceFill([
                    'password' => Hash::make($password),
                    'require_password_change' => false,
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function changePassword(ChangePasswordRequest $request): UserResource|JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->string('currentPassword')->toString(), $user->password)) {
            return response()->json(['message' => 'Your current password is incorrect.'], 422);
        }

        if (Hash::check($request->string('newPassword')->toString(), $user->password)) {
            return response()->json(['message' => 'Your new password must be different from your current password.'], 422);
        }

        $user->forceFill([
            'password' => Hash::make($request->string('newPassword')->toString()),
            'require_password_change' => false,
        ])->save();

        return new UserResource($user->fresh());
    }
}
