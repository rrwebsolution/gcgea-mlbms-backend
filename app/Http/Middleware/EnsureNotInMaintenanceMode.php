<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotInMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        $general = SystemSetting::query()->where('section', 'general')->value('value') ?? [];
        $enabled = (bool) ($general['maintenanceMode'] ?? false);
        $canBypass = $request->user()?->hasPermission('settings.general')
            || $request->user()?->hasPermission('settings.update');

        if ($enabled && ! $canBypass) {
            return new JsonResponse([
                'message' => 'The system is currently under maintenance. Please try again later.',
                'code' => 'MAINTENANCE_MODE',
            ], 503);
        }

        return $next($request);
    }
}
