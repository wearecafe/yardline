<?php

use App\Http\Middleware\EnsurePlatformStaff;
use App\Http\Middleware\EnsurePortalCustomer;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\KeepStaffInBackOffice;
use App\Http\Middleware\ProtectDemoAccounts;
use App\Http\Middleware\RestrictDrivers;
use App\Http\Middleware\TrackActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'portal' => EnsurePortalCustomer::class,
            'back-office' => EnsurePlatformStaff::class,
        ]);

        $middleware->web(append: [
            IdentifyTenant::class,
            ProtectDemoAccounts::class,
            RestrictDrivers::class,
            KeepStaffInBackOffice::class,
            TrackActivity::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The tenant must be known before route model binding runs, so bound
        // models ({product}, {order}, …) are looked up within the tenant.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: IdentifyTenant::class,
        );

        // Webhooks are verified by signature instead of CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
