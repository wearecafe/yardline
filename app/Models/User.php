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
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['tenant_id', 'name', 'email', 'role', 'password'])]
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
     * Trucks whose run this user signs in to (usually one).
     *
     * @return HasMany<Truck, $this>
     */
    public function trucks(): HasMany
    {
        return $this->hasMany(Truck::class, 'driver_id');
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
     * Set the trucks this driver can drive. They stop signing in to a truck
     * they're no longer cleared for, and start signing in to a cleared
     * truck nobody signs in to yet.
     *
     * @param  list<int>  $truckIds
     */
    public function setDrivableTrucks(array $truckIds): void
    {
        $this->drivableTrucks()->sync(
            collect($truckIds)->mapWithKeys(fn (int $id) => [$id => ['tenant_id' => $this->tenant_id]])->all(),
        );

        Truck::query()->where('driver_id', $this->id)->whereNotIn('id', $truckIds)->update(['driver_id' => null]);
        Truck::query()->whereIn('id', $truckIds)->whereNull('driver_id')->update(['driver_id' => $this->id]);
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
