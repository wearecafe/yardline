<?php

namespace App\Http\Middleware;

use App\Access\RouteAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps each person to the screens their roles reach (see RouteAccess).
 * Reading a screen they can't reach sends them to their home screen;
 * changing something there is refused.
 */
class RestrictByRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not signed in, or YardRunner staff (they use the back office).
        if (! $user instanceof User || $user->tenant_id === null || RouteAccess::allows($user, $request)) {
            return $next($request);
        }

        if ($request->isMethodSafe() && ! $request->expectsJson()) {
            return redirect()->route(RouteAccess::home($user));
        }

        abort(403, 'Your role can’t do that here.');
    }
}
