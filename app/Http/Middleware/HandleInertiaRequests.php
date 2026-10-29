<?php

namespace App\Http\Middleware;

use App\Access\RouteAccess;
use App\Alerts\StaffAlerts;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Fulfillment\DriverRun;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use App\Time\TimeStatus;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** Routes the sidebar links to. */
    private const NAV_ROUTES = [
        'dashboard', 'counter.show', 'orders.index', 'orders.queue', 'dispatch.board', 'products.index',
        'inventory.index', 'purchasing.index', 'returns.index', 'locations.index',
        'fleet.index', 'rentals.calendar', 'rentals.index', 'maintenance.index', 'rental-payments.index',
        'organizations.index', 'customers.index', 'receivables.index', 'price-lists.index',
        'driver.today', 'time.kiosk',
    ];

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
                'roles' => fn () => array_map(fn (UserRole $r) => ['value' => $r->value, 'label' => $r->label()], $request->user()?->roleList() ?? []),
                'can' => [
                    'manageBusiness' => (bool) $request->user()?->canManageBusiness(),
                    'manageUsers' => (bool) $request->user()?->hasPermission(Permission::ManageUsers),
                    'backOffice' => (bool) $request->user()?->isPlatformStaff(),
                    // Holds only the Driver role: their run is all they see.
                    'drive' => (bool) $request->user()?->isOnlyDriver(),
                    // Not an owner or admin: picks only for themselves.
                    'pick' => $request->user() !== null && ! $request->user()->canManageBusiness(),
                    // Can work the queue (start, pick, load), not just look at it.
                    'workQueue' => $request->user() !== null && RouteAccess::allowsRoute($request->user(), 'orders.pick', false),
                    'seePrices' => (bool) $request->user()?->seesPrices(),
                    'seeCosts' => (bool) $request->user()?->hasPermission(Permission::SeeCosts),
                    'setPrices' => (bool) $request->user()?->hasPermission(Permission::SetManualPrices),
                    // Set a price by hand on a product exempt from price lists.
                    'priceExempt' => (bool) $request->user()?->hasPermission(Permission::DiscountExempt),
                    'manageTime' => (bool) $request->user()?->hasPermission(Permission::ApproveTimesheets),
                    'quickbooksLog' => (bool) $request->user()?->hasRole(UserRole::Owner, UserRole::Admin, UserRole::Accounts),
                    // The profit report: anyone with See gross-profit P&L.
                    'viewProfit' => (bool) $request->user()?->hasPermission(Permission::SeeProfit),
                ],
                // The screens this person reaches, for the sidebar.
                'routes' => fn () => $this->reachableRoutes($request->user()),
                'home' => fn () => $request->user() ? RouteAccess::home($request->user()) : null,
            ],
            // Time and attendance: whether it's on, and the signed-in person's clock.
            'time' => fn () => TimeStatus::forUser($request->user()),
            // Drivers' phones share their position while their run is open.
            'shareLocation' => function () use ($request): bool {
                $user = $request->user();

                // The demo driver's position is simulated (yardrunner:demo:drive),
                // so visitors' real locations are never asked for or kept.
                return $user !== null && $user->isDriver() && ! Demo::isDemoUser($user)
                    && $user->truckAssignments()->where('date', app(DriverRun::class)->today()->toDateString())->exists();
            },
            'tenant' => fn () => CurrentTenant::get()?->only('id', 'name', 'currency'),
            // Paid add-ons the business has switched on (time is under `time`).
            'modules' => fn () => ['rentals' => (bool) CurrentTenant::get()?->rentals_enabled],
            // The trial countdown and payment problems, for the banner.
            'billing' => function () use ($request) {
                $tenant = CurrentTenant::get();

                if ($tenant === null || $request->user() === null || $request->user()->isPlatformStaff()) {
                    return null;
                }

                $state = $tenant->billing();

                return $state->exempt ? null : [
                    'status' => $state->status->value,
                    'has_access' => $state->hasAccess,
                    'subscribed' => $state->subscribed,
                    'trial_days_left' => $state->subscribed ? null : $state->trialDaysLeft,
                    'trial_ends_at' => $state->trialEndsAt?->toDateString(),
                    'cancelling' => $state->cancelling,
                    'period_ends_at' => $state->periodEndsAt?->toIso8601String(),
                ];
            },
            'isDemo' => fn () => Demo::isDemoUser($request->user()),
            // Web push: the server's public key, so the app can keep this
            // device's alerts current, and whether this person's roles get
            // any (null: alerts aren't set up here).
            'push' => function () use ($request) {
                $user = $request->user();

                if (! $user instanceof User || $user->tenant_id === null || ! WebPushChannel::configured() || Demo::isCurrentTenant()) {
                    return null;
                }

                return ['key' => (string) config('yardrunner.push.public_key'), 'alerts' => StaffAlerts::for($user) !== []];
            },
            'portal_flash' => fn () => $request->hasSession() ? $request->session()->get('portal_flash') : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Sidebar screens, and whether this person reaches each.
     *
     * @return array<string, bool>
     */
    private function reachableRoutes(?User $user): array
    {
        $routes = [];

        foreach (self::NAV_ROUTES as $name) {
            $routes[$name] = $user !== null && RouteAccess::allowsRoute($user, $name);
        }

        return $routes;
    }
}
