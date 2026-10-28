<?php

namespace App\Http\Middleware;

use App\Access\HasYard;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone who works only some yards can't open or change an order, rental,
 * stop, stock level, cycle count or rental unit from another yard: the
 * record isn't found, as if it were another business's. Runs after route
 * model binding, on the records bound to the route.
 *
 * The driver app and handing over or loading shipments keep their own
 * checks (assigned stops, the pick queue), so they're left to those.
 */
class RestrictByYard
{
    private const OWN_CHECKS = ['driver.*', 'shipments.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if (! $user instanceof User || $user->tenant_id === null || ! $user->isYardScoped() || $route === null || $route->named(...self::OWN_CHECKS)) {
            return $next($request);
        }

        foreach ($route->parameters() as $bound) {
            if ($bound instanceof HasYard && ! self::works($user, $bound)) {
                abort(404);
            }
        }

        return $next($request);
    }

    /** Whether they work any of the record's yards (or it has none). */
    public static function works(User $user, HasYard $record): bool
    {
        $yards = $record->yardIds();

        if ($yards === []) {
            return true;
        }

        foreach ($yards as $yard) {
            if ($user->worksAt($yard)) {
                return true;
            }
        }

        return false;
    }
}
