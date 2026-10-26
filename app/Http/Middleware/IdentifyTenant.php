<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the signed-in user. Runs before route model
 * binding (see bootstrap/app.php) so bound models are tenant-scoped.
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->tenant_id !== null) {
            CurrentTenant::set($user->tenant_id);
        }

        return $next($request);
    }
}
