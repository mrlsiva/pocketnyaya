<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireApiWriteAbility;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCitizenContext;
use App\Http\Middleware\SetStaffContext;
use App\Http\Middleware\SetTenantContext;
use App\Integrations\IntegrationNotConfigured;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'citizen.context' => SetCitizenContext::class,
            'tenant.context' => SetTenantContext::class,
            'staff.context' => SetStaffContext::class,
            'pn.can' => RequirePermission::class,
            'api.ability' => RequireApiWriteAbility::class,
        ]);

        // Context middleware must run before route model binding (C33).
        foreach ([
            EnsureEmailIsVerified::class,
            EnsureUserIsActive::class,
            SetCitizenContext::class,
            SetStaffContext::class,
            SetTenantContext::class,
            RequirePermission::class,
        ] as $contextMiddleware) {
            $middleware->prependToPriorityList(SubstituteBindings::class, $contextMiddleware);
        }

        $middleware->throttleApi();
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API clients always get JSON errors, including for unknown routes.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // A switched-off outside service: a plain message, never a server error, and not an error in the log.
        $exceptions->dontReport(IntegrationNotConfigured::class);
        $exceptions->render(function (IntegrationNotConfigured $e, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => $e->getMessage(), 'integration' => $e->integration], 503)
                : back()->withErrors(['integration' => $e->getMessage()]);
        });
    })->create();
