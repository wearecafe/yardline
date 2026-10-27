<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drivers see their run, proof of delivery and their own account
 * settings. Anything else in the yard (orders, prices, receivables,
 * settings) sends them back to their run.
 */
class RestrictDrivers
{
    /**
     * Route names a driver may use.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'driver.*',
        'time.self.*',
        'shipments.deliver',
        'shipments.complete',
        'files.show',
        'profile.edit',
        'profile.update',
        'security.edit',
        'user-password.update',
        'user-profile-information.*',
        'appearance.edit',
        'password.*',
        'two-factor.*',
        'passkey*',
        'verification.*',
        'logout',
        'billing.paused',
        'help.*',
        'home',
        'well-known.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isDriver()) {
            return $next($request);
        }

        $route = $request->route();
        $name = is_object($route) ? $route->getName() : null;

        // Fortify and unnamed framework routes (/up, assets) pass through.
        if ($name === null || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            return redirect()->route('driver.today');
        }

        abort(403, 'Drivers can only work their own run.');
    }
}
