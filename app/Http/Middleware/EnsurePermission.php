<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Handle an incoming request.
     *
     * Usage: Route::middleware('permission:members.create')->...
     * Accepts multiple codes (any-of): Route::middleware('permission:loans.approve,loans.override_eligibility')
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        $user = $request->user();

        if (! $user || ! collect($codes)->contains(fn (string $code) => $user->hasPermission($code))) {
            abort(403, "You don't have permission to perform this action.");
        }

        return $next($request);
    }
}
