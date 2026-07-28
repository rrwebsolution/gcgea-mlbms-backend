<?php

use App\Exceptions\ApprovalActionConflictException;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\EnsureNotInMaintenanceMode;
use App\Providers\AuthServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        AuthServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'password.changed' => RequirePasswordChange::class,
            'maintenance' => EnsureNotInMaintenanceMode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ApprovalActionConflictException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 409);
        });
    })->create();
