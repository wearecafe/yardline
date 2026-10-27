<?php

namespace App\Http\Middleware;

use App\Fulfillment\DriverRun;
use App\Pricing\PricingSettings;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use App\Time\TimeStatus;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'can' => [
                    'manageBusiness' => (bool) $request->user()?->canManageBusiness(),
                    'manageUsers' => (bool) $request->user()?->canManageUsers(),
                    'drive' => (bool) $request->user()?->isDriver(),
                    // Order pickers only work the order queue.
                    'pick' => (bool) $request->user()?->isPicker(),
                    // Set a price by hand on a product exempt from price lists.
                    'priceExempt' => (bool) $request->user()?->canManageBusiness()
                        && PricingSettings::current()->canPriceExempt($request->user()),
                    'manageTime' => (bool) $request->user()?->canManageBusiness(),
                ],
            ],
            // Time and attendance: whether it's on, and the signed-in person's clock.
            'time' => fn () => TimeStatus::forUser($request->user()),
            // Drivers' phones share their position while their run is open.
            'shareLocation' => function () use ($request): bool {
                $user = $request->user();

                // The demo driver's position is simulated (yardline:demo:drive),
                // so visitors' real locations are never asked for or kept.
                return $user !== null && $user->isDriver() && ! Demo::isDemoUser($user)
                    && $user->truckAssignments()->where('date', app(DriverRun::class)->today()->toDateString())->exists();
            },
            'tenant' => fn () => CurrentTenant::get()?->only('id', 'name', 'currency'),
            'isDemo' => fn () => Demo::isDemoUser($request->user()),
            'portal_flash' => fn () => $request->hasSession() ? $request->session()->get('portal_flash') : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
