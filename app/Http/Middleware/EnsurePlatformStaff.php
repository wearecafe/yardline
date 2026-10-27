<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The back office (/admin) is for Yardline staff only, and they must have
 * two-factor authentication on (see yardline.back_office.require_two_factor).
 */
class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Tenants never learn the back office is here.
        abort_unless($user instanceof User && $user->isPlatformStaff(), 404);

        if (config('yardline.back_office.require_two_factor') && $user->two_factor_confirmed_at === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Turn on two-factor authentication to use the back office.']);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
