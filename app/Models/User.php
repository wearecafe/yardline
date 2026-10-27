<?php

namespace App\Models;

use App\Access\AccessSettings;
use App\Enums\Permission;
use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property UserRole $role the user's main role, the highest of their roles
 * @property list<string>|null $roles every role they hold (null: just $role)
 * @property array<string, bool>|null $permissions permissions granted (true) or removed (false) on top of their roles
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property bool $is_platform_staff
 * @property CarbonImmutable|null $last_seen_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['tenant_id', 'name', 'email', 'phone', 'role', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'sales',
    ];

    protected static function booted(): void
    {
        // Setting only the main role (a factory, an older call site) means
        // that's the one role they hold.
        static::saving(function (User $user) {
            if ($user->isDirty('role') && ! $user->isDirty('roles')) {
                $user->roles = null;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'roles' => 'array',
            'permissions' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_staff' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The days this driver drives a truck, picked on the dispatch board.
     *
     * @return HasMany<TruckAssignment, $this>
     */
    public function truckAssignments(): HasMany
    {
        return $this->hasMany(TruckAssignment::class);
    }

    /**
     * Where this driver's phone has been.
     *
     * @return HasMany<DriverPing, $this>
     */
    public function driverPings(): HasMany
    {
        return $this->hasMany(DriverPing::class);
    }

    /**
     * Trucks this driver is able to drive.
     *
     * @return BelongsToMany<Truck, $this>
     */
    public function drivableTrucks(): BelongsToMany
    {
        return $this->belongsToMany(Truck::class, 'driver_truck')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    /**
     * Set the trucks this driver can drive. Days ahead on a truck they're
     * no longer cleared for go back to dispatch to fill.
     *
     * @param  list<int>  $truckIds
     */
    public function setDrivableTrucks(array $truckIds): void
    {
        $this->drivableTrucks()->sync(
            collect($truckIds)->mapWithKeys(fn (int $id) => [$id => ['tenant_id' => $this->tenant_id]])->all(),
        );

        $today = CarbonImmutable::today($this->tenant->timezone ?? 'America/Toronto')->toDateString();

        $this->truckAssignments()
            ->whereNotIn('truck_id', $truckIds)
            ->where('date', '>=', $today)
            ->delete();
    }

    /**
     * Every role this user holds, highest first.
     *
     * @return list<UserRole>
     */
    public function roleList(): array
    {
        $values = $this->roles === null || ($this->isDirty('role') && ! $this->isDirty('roles'))
            ? [$this->role]
            : $this->roles;
        $roles = [];

        foreach ($values as $value) {
            $role = $value instanceof UserRole ? $value : UserRole::fromValue(is_string($value) ? $value : null);

            if ($role !== null && ! in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }

        usort($roles, fn (UserRole $a, UserRole $b) => $a->rank() <=> $b->rank());

        return $roles;
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return array_intersect(array_map(fn (UserRole $r) => $r->value, $roles), array_map(fn (UserRole $r) => $r->value, $this->roleList())) !== [];
    }

    /**
     * Give this user exactly these roles. The highest becomes their main
     * role. Doesn't save.
     *
     * @param  list<UserRole>  $roles
     */
    public function setRoles(array $roles): static
    {
        $roles = array_values(array_unique($roles, SORT_REGULAR));
        usort($roles, fn (UserRole $a, UserRole $b) => $a->rank() <=> $b->rank());

        $this->role = $roles[0] ?? UserRole::Sales;
        $this->roles = array_map(fn (UserRole $r) => $r->value, $roles);

        return $this;
    }

    /** Owner or Admin: settings, price lists, credit settings, every screen. */
    public function canManageBusiness(): bool
    {
        return $this->hasRole(UserRole::Owner, UserRole::Admin);
    }

    public function canManageUsers(): bool
    {
        return $this->hasPermission(Permission::ManageUsers);
    }

    /**
     * Whether this user holds a permission: from their roles, as this
     * business sets them, then anything granted or removed on them. The
     * owner holds every permission.
     */
    public function hasPermission(Permission $permission): bool
    {
        if ($this->hasRole(UserRole::Owner)) {
            return true;
        }

        if ($permission->requires() !== null && ! $this->hasPermission($permission->requires())) {
            return false;
        }

        $own = $this->permissions ?? [];

        if (array_key_exists($permission->value, $own)) {
            return (bool) $own[$permission->value];
        }

        return $this->permissionFromRoles($permission);
    }

    /** What their roles give, before anything granted or removed on them. */
    public function permissionFromRoles(Permission $permission): bool
    {
        $settings = AccessSettings::for($this->tenant);

        foreach ($this->roleList() as $role) {
            if ($settings->roleGives($role, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Permissions this user holds.
     *
     * @return list<Permission>
     */
    public function permissionList(): array
    {
        return array_values(array_filter(Permission::cases(), fn (Permission $p) => $this->hasPermission($p)));
    }

    /**
     * The most one refund by this user can be, in cents, or null for no
     * limit: the highest limit among their roles. Sales refunds goods and
     * Rental desk refunds rentals, each up to their role's limit.
     *
     * @param  'goods'|'rental'|null  $kind
     */
    public function refundLimit(?string $kind = null): ?int
    {
        $settings = AccessSettings::for($this->tenant);
        $limit = 0;

        foreach ($this->roleList() as $role) {
            if (($kind === 'rental' && $role === UserRole::Sales) || ($kind === 'goods' && $role === UserRole::RentalDesk)) {
                continue;
            }

            $roleLimit = $settings->refundLimit($role);

            if ($roleLimit === null) {
                return null;
            }

            $limit = max($limit, $roleLimit);
        }

        return $limit;
    }

    /**
     * @param  'goods'|'rental'|null  $kind
     */
    public function canRefund(int $cents, ?string $kind = null): bool
    {
        if ($this->hasPermission(Permission::RefundAboveLimit)) {
            return true;
        }

        $limit = $this->refundLimit($kind);

        return $limit === null || $cents <= $limit;
    }

    /** Sees selling prices, totals and balances (not yard crew, dispatch, drivers or service techs). */
    public function seesPrices(): bool
    {
        foreach ($this->roleList() as $role) {
            if ($role->seesPrices()) {
                return true;
            }
        }

        return false;
    }

    public function isDriver(): bool
    {
        return $this->hasRole(UserRole::Driver);
    }

    /** Holds only the Driver role: their run is all they see. */
    public function isOnlyDriver(): bool
    {
        return $this->roleList() === [UserRole::Driver];
    }

    /** Can switch in on a shared tablet or truck device with a PIN. */
    public function switchesWithPin(): bool
    {
        if ($this->canManageBusiness()) {
            return false;
        }

        foreach ($this->roleList() as $role) {
            if ($role->switchesWithPin()) {
                return true;
            }
        }

        return false;
    }

    /**
     * YardRunner staff: no tenant of their own, and they use the back office
     * (/admin) instead of a yard. Never set from a request; see the
     * yardline:staff:add command.
     */
    public function isPlatformStaff(): bool
    {
        return $this->is_platform_staff && $this->tenant_id === null;
    }

    /** Works only the yards they're assigned (everyone but Owner and Admin). */
    public function isYardScoped(): bool
    {
        return ! $this->canManageBusiness();
    }

    /**
     * The yards this user is assigned to. Owner and Admin work every yard.
     *
     * @return BelongsToMany<Location, $this>
     */
    public function yards(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_user')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    /**
     * @param  array<int, int>  $locationIds
     */
    public function setYards(array $locationIds): void
    {
        $this->yards()->sync(
            collect($locationIds)->mapWithKeys(fn (int $id) => [$id => ['tenant_id' => $this->tenant_id]])->all(),
        );
    }

    /**
     * Yards this user works, or null for every yard.
     *
     * @return list<int>|null
     */
    public function yardIds(): ?array
    {
        if (! $this->isYardScoped()) {
            return null;
        }

        return array_values($this->yards()->pluck('locations.id')->map(fn ($id) => (int) $id)->all());
    }

    public function worksAt(?int $locationId): bool
    {
        $yards = $this->yardIds();

        return $yards === null || ($locationId !== null && in_array($locationId, $yards, true));
    }
}
