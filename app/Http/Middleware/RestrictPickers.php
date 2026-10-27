<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Order pickers see the order queue and pick lists for their yards, and
 * their own account settings. Anything else (order editing, prices,
 * stock, receivables, settings) sends them back to the queue.
 */
class RestrictPickers
{
    /**
     * Route names an order picker may use.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'orders.queue',
        'orders.pick',
        'shipments.assign',
        'shipments.pick',
        'shipments.lines.record',
        'shipments.picked',
        'shipments.load',
        'shipments.sign-off',
        'shipments.reject',
        'shipments.ticket',
        'picker.switch',
        'time.self.*',
        'time.kiosk*',
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

        if (! $user instanceof User || ! $user->isPicker()) {
            return $next($request);
        }

        $route = $request->route();
        $name = is_object($route) ? $route->getName() : null;

        // Fortify and unnamed framework routes (/up, assets) pass through.
        if ($name === null || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            return redirect()->route('orders.queue');
        }

        abort(403, 'Order pickers can only work the order queue.');
    }
}
