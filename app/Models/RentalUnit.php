<?php

namespace App\Models;

use App\Access\HasYard;
use App\Enums\RentalStatus;
use App\Enums\RentalUnitStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\RentalUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One machine in the rental fleet, tracked by serial number.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $location_id
 * @property string $serial
 * @property RentalUnitStatus $status
 * @property string|null $hour_meter
 * @property int|null $service_interval_hours
 * @property int|null $service_interval_days
 * @property string|null $last_service_hours
 * @property CarbonImmutable|null $last_service_on
 * @property CarbonImmutable|null $purchased_on
 * @property int|null $purchase_cost_cents
 * @property string|null $photo_path
 * @property string|null $notes
 * @property-read Product $product
 * @property-read Location $location
 */
#[Fillable([
    'product_id', 'location_id', 'serial', 'status', 'hour_meter',
    'service_interval_hours', 'service_interval_days', 'last_service_hours',
    'last_service_on', 'purchased_on', 'purchase_cost_cents', 'photo_path', 'notes',
])]
class RentalUnit extends Model implements HasYard
{
    /** @use HasFactory<RentalUnitFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'available',
    ];

    protected function casts(): array
    {
        return [
            'status' => RentalUnitStatus::class,
            'hour_meter' => 'decimal:1',
            'last_service_hours' => 'decimal:1',
            'last_service_on' => 'date',
            'purchased_on' => 'date',
            'purchase_cost_cents' => 'integer',
            'service_interval_hours' => 'integer',
            'service_interval_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<RentalItem, $this>
     */
    public function rentalItems(): HasMany
    {
        return $this->hasMany(RentalItem::class);
    }

    /**
     * @return HasMany<MaintenanceRecord, $this>
     */
    public function maintenance(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    public function name(): string
    {
        return $this->product->name;
    }

    public function serviceIntervalHours(): ?int
    {
        return $this->service_interval_hours ?? $this->product->service_interval_hours;
    }

    public function serviceIntervalDays(): ?int
    {
        return $this->service_interval_days ?? $this->product->service_interval_days;
    }

    /**
     * Hours left before the next hour-based service, or null if not tracked.
     */
    public function hoursUntilService(): ?float
    {
        $interval = $this->serviceIntervalHours();

        if ($interval === null || $this->hour_meter === null) {
            return null;
        }

        $since = (float) $this->hour_meter - (float) ($this->last_service_hours ?? 0);

        return round($interval - $since, 1);
    }

    public function serviceDueOn(): ?CarbonImmutable
    {
        $days = $this->serviceIntervalDays();

        if ($days === null) {
            return null;
        }

        return ($this->last_service_on ?? $this->created_at ?? CarbonImmutable::now())->addDays($days);
    }

    /**
     * Service is due by hours or by date. Due units are locked out of new
     * rentals until the service is logged.
     */
    public function serviceDue(): bool
    {
        $hours = $this->hoursUntilService();
        $date = $this->serviceDueOn();

        return ($hours !== null && $hours <= 0) || ($date !== null && $date->isPast());
    }

    public function rentable(): bool
    {
        return $this->status === RentalUnitStatus::Available && ! $this->serviceDue();
    }

    /**
     * The rental currently holding this unit (out now), if any.
     */
    public function currentRental(): ?Rental
    {
        return Rental::query()
            ->where('status', RentalStatus::Out->value)
            ->whereHas('items', fn ($query) => $query->where('rental_unit_id', $this->id))
            ->latest('starts_at')
            ->first();
    }

    /** @return list<int> */
    public function yardIds(): array
    {
        return [(int) $this->location_id];
    }
}
