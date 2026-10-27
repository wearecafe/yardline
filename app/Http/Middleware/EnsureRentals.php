<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rentals is a paid add-on: its screens, and the public booking page, are
 * there once the business switches it on under Settings → Billing (free
 * during the trial).
 */
class EnsureRentals
{
    public function handle(Request $request, Closure $next): Response
    {
        // The booking page names its yard in the URL; everything else is signed in.
        $bound = $request->route('tenant');
        $tenant = $bound instanceof Tenant ? $bound : CurrentTenant::get();

        if ($tenant !== null && $tenant->rentals_enabled && ($bound === null || $tenant->billing()->hasAccess)) {
            return $next($request);
        }

        $user = $request->user();

        if ($bound === null && $user instanceof User && $user->canManageBusiness() && $request->isMethodSafe()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Switch on Rentals to manage your rental fleet.']);

            return redirect()->route('billing.edit');
        }

        abort(404);
    }
}
