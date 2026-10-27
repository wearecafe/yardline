<?php

namespace App\Http\Middleware;

use App\Audit\Audit;
use App\Models\Customer;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contractor portal pages need a signed-in contact.
 */
class EnsurePortalCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $customerId = $request->session()->get('portal.customer_id');
        $customer = is_int($customerId) && CurrentTenant::id() !== null
            ? Customer::query()->with('organization')->find($customerId)
            : null;

        if ($customer === null) {
            $request->session()->forget('portal');
            $slug = $request->session()->get('portal.slug');

            return is_string($slug) ? redirect()->route('portal.login', $slug) : redirect('/');
        }

        // A yard whose YardRunner account is paused takes no portal orders.
        abort_unless(CurrentTenant::get()?->billing()->hasAccess ?? false, 404);

        $request->attributes->set('portalCustomer', $customer);
        Audit::$customerId = $customer->id;
        $tenant = CurrentTenant::get();

        Inertia::share('portal', [
            'customer' => ['id' => $customer->id, 'name' => $customer->full_name, 'first_name' => $customer->first_name],
            'organization' => $customer->organization?->only('id', 'name', 'account_number'),
            'yard' => ['name' => $tenant->name ?? 'YardRunner', 'slug' => $tenant->slug ?? ''],
        ]);

        try {
            return $next($request);
        } finally {
            // Static, so it would outlive the request in a long-running
            // worker and pin later audit events on this customer.
            Audit::$customerId = null;
        }
    }
}
