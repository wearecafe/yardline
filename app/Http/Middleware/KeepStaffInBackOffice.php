<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * YardRunner staff have no yard of their own, so the yard's pages would be
 * empty for them (and could try to save rows with no tenant). They get the
 * back office and their own account settings; anything else sends them to
 * the back office.
 */
class KeepStaffInBackOffice
{
    /**
     * Route names staff may use outside the back office.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'admin.*',
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
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isPlatformStaff()) {
            return $next($request);
        }

        $route = $request->route();
        $name = is_object($route) ? $route->getName() : null;

        // Fortify and unnamed framework routes (/up, assets) pass through.
        if ($name === null || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            return redirect()->route('admin.overview');
        }

        abort(403, 'YardRunner staff work from the back office.');
    }
}
