<?php

namespace App\Access;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The screens each role reaches, by route name. Owner and Admin reach
 * everything (some settings are owner-only; see the gates). Everyone else
 * reaches the union of their roles' routes: "full" routes with any method,
 * "view" routes only to read (GET). Anything not listed sends them to
 * their home screen, or is refused if it would change something.
 *
 * Pages that a role reaches can still hide what it mustn't see, like
 * prices for Yard crew (see User::seesPrices) or costs without See costs
 * and margins.
 */
final class RouteAccess
{
    /** Everyone who signs in: their account, help and public pages. */
    public const COMMON = [
        'time.self.*',
        'files.show',
        'profile.edit',
        'profile.update',
        'security.edit',
        'user-password.update',
        'user-profile-information.*',
        'appearance.edit',
        'alerts.*',
        'pwa.start',
        'password.*',
        'two-factor.*',
        'passkey*',
        'verification.*',
        'logout',
        'billing.paused',
        'help.*',
        'home',
        'well-known.*',
        'quotes.public',
        'quotes.accept',
        'book.*',
        'rentals.public-sign*',
        // A shared tablet: switch to someone else with their PIN.
        'picker.switch',
    ];

    /**
     * @var array<string, array{full?: list<string>, view?: list<string>}>
     */
    public const ROLES = [
        'sales' => [
            'full' => [
                'dashboard',
                'counter.*',
                'orders.index', 'orders.create', 'orders.store', 'orders.show', 'orders.update', 'orders.destroy',
                'orders.lines.*', 'orders.confirm', 'orders.fulfill', 'orders.cancel', 'orders.payments.store', 'orders.payment-link', 'orders.quote',
                // Refunds up to their role's limit; above it, someone approves.
                'orders.payments.refund', 'orders.refund-and-cancel',
                'orders.returns.*', 'returns.index', 'credit-notes.*', 'pallet-returns.store',
                'organizations.index', 'organizations.create', 'organizations.store', 'organizations.show', 'organizations.edit', 'organizations.update',
                'organizations.locations.*', 'organizations.contacts.*',
                'customers.*',
                'products.fit',
                // Handing over a pickup, and quality checks if the business lets Sales sign off.
                'shipments.deliver', 'shipments.complete', 'shipments.ticket',
                'shipments.sign-off', 'shipments.reject',
                'time.kiosk*',
            ],
            'view' => [
                'products.index', 'products.edit',
                'inventory.index',
                'orders.queue',
                'dispatch.board',
                'invoices.show',
                'rentals.calendar',
            ],
        ],
        'rental_desk' => [
            'full' => [
                'rentals.*',
                'rental-payments.index',
                'customers.*',
                'returns.index', 'credit-notes.*',
                'time.kiosk*',
            ],
            'view' => [
                'fleet.index', 'fleet.units.show',
                'orders.index', 'orders.show',
                'products.index', 'products.edit',
                'organizations.index', 'organizations.show',
                'dispatch.board',
                'invoices.show',
            ],
        ],
        'yard_crew' => [
            'full' => [
                'orders.queue', 'orders.pick',
                'shipments.assign', 'shipments.pick', 'shipments.lines.record', 'shipments.picked', 'shipments.load',
                'shipments.sign-off', 'shipments.reject', 'shipments.ticket',
                'picker.switch',
                'rentals.inspect', 'rentals.inspect.store',
                'time.kiosk*',
            ],
            'view' => [
                'inventory.index',
                'dispatch.board',
                'fleet.index',
                'rentals.calendar',
            ],
        ],
        'dispatcher' => [
            'full' => [
                'dispatch.*',
                'stops.*',
                // Quality checks, if the business lets Dispatch sign off.
                'shipments.sign-off', 'shipments.reject',
                'time.kiosk*',
            ],
            'view' => [
                'orders.queue',
                'fleet.index',
                'rentals.calendar',
                'driver.today', 'driver.stops.show',
            ],
        ],
        'driver' => [
            'full' => [
                'driver.*',
                'shipments.deliver',
                'shipments.complete',
                'picker.switch',
            ],
        ],
        'purchasing' => [
            'full' => [
                'purchasing.*',
                'suppliers.store',
                'inventory.*',
                'stock-levels.update',
                'stock-transfers.store',
                'cycle-counts.*',
                'products.*',
                'time.kiosk*',
            ],
            'view' => [
                'orders.index', 'orders.show',
                'orders.queue',
                'returns.index', 'credit-notes.show',
                'locations.index',
            ],
        ],
        'accounts' => [
            'full' => [
                'dashboard',
                'receivables.index',
                'organizations.statement*',
                'invoices.show',
                'returns.index', 'orders.returns.*', 'credit-notes.*', 'pallet-returns.store',
                'orders.payments.refund',
                'organizations.index', 'organizations.show', 'organizations.edit', 'organizations.update',
                'quickbooks.edit', 'quickbooks.resend',
                'time.kiosk*',
            ],
            'view' => [
                'orders.index', 'orders.show', 'orders.queue',
                'counter.show',
                'customers.index', 'customers.edit',
                'products.index', 'products.edit',
                'inventory.index',
                'purchasing.index', 'purchasing.show',
                'price-lists.index', 'price-lists.edit',
                'rentals.index', 'rentals.show', 'rentals.agreement',
                'rental-payments.index',
            ],
        ],
        'service_tech' => [
            'full' => [
                'fleet.*',
                'maintenance.*',
                'picker.switch',
                'time.kiosk*',
            ],
            'view' => [
                'rentals.calendar',
                'rentals.inspect',
            ],
        ],
        // Their own time (time.self.*, for everyone) and nothing else.
        'time_only' => [],
    ];

    /**
     * Where each role starts, in order of preference for someone holding
     * several.
     */
    private const HOME = [
        'sales' => 'dashboard',
        'accounts' => 'dashboard',
        'rental_desk' => 'rentals.index',
        'dispatcher' => 'dispatch.board',
        'purchasing' => 'inventory.index',
        'yard_crew' => 'orders.queue',
        'service_tech' => 'maintenance.index',
        'driver' => 'driver.today',
        'time_only' => 'time.self.show',
    ];

    /** Whether the user may use this request's route. */
    public static function allows(User $user, Request $request): bool
    {
        if ($user->canManageBusiness()) {
            return true;
        }

        $route = $request->route();
        $name = is_object($route) ? $route->getName() : null;

        // Fortify and unnamed framework routes (/up, assets) pass through.
        if ($name === null || $request->routeIs(...self::COMMON)) {
            return true;
        }

        // Routes behind a gate (settings, the P&L, timesheets, users) are
        // decided by that permission, whatever the role: someone given
        // Approve timesheets reaches the timesheets. People who only drive
        // stay on their run.
        $abilities = self::abilities($route->gatherMiddleware());

        if ($abilities !== [] && (! $user->isOnlyDriver() || Gate::forUser($user)->check($abilities))) {
            return true;
        }

        return self::allowsRoute($user, $name, $request->isMethodSafe());
    }

    /**
     * Whether the user may reach a named route: to read it ($reading), or
     * to change something through it.
     */
    public static function allowsRoute(User $user, string $name, bool $reading = true): bool
    {
        if ($user->canManageBusiness()) {
            return true;
        }

        foreach ($user->roleList() as $role) {
            $map = self::ROLES[$role->value] ?? [];
            $patterns = $reading ? [...($map['full'] ?? []), ...($map['view'] ?? [])] : ($map['full'] ?? []);

            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The gates a route's can: middleware checks.
     *
     * @param  array<int, mixed>  $middleware
     * @return list<string>
     */
    private static function abilities(array $middleware): array
    {
        $abilities = [];

        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'can:')) {
                $abilities[] = explode(',', substr($m, 4))[0];
            }
        }

        return $abilities;
    }

    /** The route a user lands on, and is sent back to. */
    public static function home(User $user): string
    {
        if ($user->canManageBusiness()) {
            return 'dashboard';
        }

        $values = array_map(fn (UserRole $r) => $r->value, $user->roleList());

        foreach (self::HOME as $role => $route) {
            if (in_array($role, $values, true)) {
                return $route;
            }
        }

        return 'profile.edit';
    }
}
