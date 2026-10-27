<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks a tenant's people out of YardRunner once the trial has ended
 * without a subscription, the subscription is unpaid or cancelled, or
 * staff have suspended them. Owners and admins land on the billing page
 * to subscribe; everyone else sees that the account is paused. Their own
 * account settings, sign-out and help still work.
 */
class EnsureBilling
{
    /**
     * Route names that work while locked.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'billing.*',
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
        'help.*',
        'home',
        'well-known.*',
        'webhooks.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = CurrentTenant::get();

        if (! $user instanceof User || $tenant === null || $user->isPlatformStaff()) {
            return $next($request);
        }

        $route = $request->route();
        $name = is_object($route) ? $route->getName() : null;

        if ($name === null || $request->routeIs(...self::ALLOWED) || $tenant->billing()->hasAccess) {
            return $next($request);
        }

        if (! $request->isMethodSafe()) {
            abort(402, 'This YardRunner account is paused.');
        }

        if ($user->canManageBusiness() && $tenant->suspended_at === null) {
            return redirect()->route('billing.edit');
        }

        return redirect()->route('billing.paused');
    }
}
