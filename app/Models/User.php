<?php

namespace App\Models;

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
 * @property UserRole $role
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
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
        'role' => 'staff',
    ];

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
            'two_factor_confirmed_at' => 'datetime',
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

    public function canManageUsers(): bool
    {
        return $this->role->canManageUsers();
    }

    public function canManageBusiness(): bool
    {
        return $this->role->canManageBusiness();
    }

    public function isDriver(): bool
    {
        return $this->role->isDriver();
    }
}
