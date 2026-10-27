<?php

namespace App\Models;

use App\Audit\Audited;
use App\Tenancy\BelongsToTenant;
use App\Time\TimeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

/**
 * Someone whose hours YardRunner keeps. Linked to a user when they sign
 * in; yard hands who only punch at the counter tablet don't need one.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property int|null $location_id home yard
 * @property string $name
 * @property string|null $job_title
 * @property string $pay_type hourly | salaried
 * @property bool $tracks_time
 * @property bool $overtime_exempt
 * @property bool $take_home_truck starts and ends shifts away from the yard
 * @property bool $photo_on_punch in the group photographed at the kiosk
 * @property string|null $payroll_id their number in the payroll system
 * @property string|null $pin_hash
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 * @property-read Location|null $location
 */
#[Fillable(['user_id', 'location_id', 'name', 'job_title', 'pay_type', 'tracks_time', 'overtime_exempt', 'take_home_truck', 'photo_on_punch', 'payroll_id'])]
#[Hidden(['pin_hash'])]
class Employee extends Model
{
    use Audited, BelongsToTenant;

    public const AUDITED = ['name', 'pay_type', 'tracks_time', 'overtime_exempt', 'take_home_truck', 'photo_on_punch', 'payroll_id', 'location_id', 'archived_at'];

    public const PAY_TYPES = ['hourly' => 'Hourly', 'salaried' => 'Salaried'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'pay_type' => 'hourly',
        'tracks_time' => true,
        'overtime_exempt' => false,
        'take_home_truck' => false,
        'photo_on_punch' => false,
    ];

    protected function casts(): array
    {
        return [
            'tracks_time' => 'boolean',
            'overtime_exempt' => 'boolean',
            'take_home_truck' => 'boolean',
            'photo_on_punch' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<Employee>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Active and clocking in.
     *
     * @param  Builder<Employee>  $query
     */
    public function scopeTracked(Builder $query): void
    {
        $query->whereNull('archived_at')->where('tracks_time', true);
    }

    public static function forUser(?User $user): ?self
    {
        if ($user === null) {
            return null;
        }

        return self::query()->active()->where('user_id', $user->id)->first();
    }

    public function setPin(?string $pin): void
    {
        $this->pin_hash = $pin === null || $pin === '' ? null : Hash::make($pin);
    }

    public function checkPin(string $pin): bool
    {
        return $this->pin_hash !== null && Hash::check($pin, $this->pin_hash);
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    /**
     * No overtime for salaried staff or roles marked exempt.
     */
    public function earnsOvertime(): bool
    {
        return ! $this->overtime_exempt && $this->pay_type === 'hourly';
    }

    public function needsPhoto(TimeSettings $settings): bool
    {
        return match ($settings->photo) {
            'everyone' => true,
            'selected' => $this->photo_on_punch,
            default => false,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<TimePunch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(TimePunch::class);
    }

    /**
     * @return HasMany<Timesheet, $this>
     */
    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }
}
