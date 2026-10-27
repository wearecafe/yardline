<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Onboarding\Setup;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps owners and admins of a new business in Setup until its details and
 * yards are saved. Nobody else is held back, and people who haven't
 * verified their email yet are left to the `verified` middleware first.
 */
class EnsureSetUp
{
    /**
     * Route names that work before Setup is done.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'setup.*',
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
        'brand.logo',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = CurrentTenant::get();

        if (! $user instanceof User || $tenant === null || $tenant->onboarded_at !== null
            || $user->isPlatformStaff() || ! $user->canManageBusiness() || ! $user->hasVerifiedEmail()) {
            return $next($request);
        }

        $route = $request->route();
        $name = is_object($route) ? $route->getName() : null;

        if ($name === null || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(409, 'Finish setting up YardRunner first.');
        }

        return redirect()->route('setup.step', Setup::for($tenant)->resumeStep());
    }
}
