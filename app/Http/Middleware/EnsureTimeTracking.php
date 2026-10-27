<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Time\TimeSettings;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Time and attendance is an add-on: its screens are there once the
 * business turns it on under Settings → Time.
 */
class EnsureTimeTracking
{
    public function handle(Request $request, Closure $next): Response
    {
        if (TimeSettings::current()->enabled) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && $user->canManageBusiness() && $request->isMethodSafe()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Turn on time and attendance to start tracking hours.']);

            return redirect()->route('time-settings.edit');
        }

        abort(404);
    }
}
