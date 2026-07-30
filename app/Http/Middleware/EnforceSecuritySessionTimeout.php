<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSecuritySessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $lastActivity = $request->session()->get('security_last_activity_at');
        $timeoutSeconds = SystemSetting::security()['sessionTimeoutMinutes'] * 60;

        if ($lastActivity && now()->timestamp - (int) $lastActivity > $timeoutSeconds) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return new JsonResponse(['message' => 'Your session expired due to inactivity.'], 401);
        }

        $request->session()->put('security_last_activity_at', now()->timestamp);

        return $next($request);
    }
}
