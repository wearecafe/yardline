<?php

namespace App\Models;

use App\Enums\FuelLevel;
use App\Enums\InspectionKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $rental_id
 * @property int $rental_unit_id
 * @property InspectionKind $kind
 * @property string|null $hour_meter
 * @property FuelLevel|null $fuel
 * @property bool $clean
 * @property string|null $notes
 * @property int|null $user_id
 * @property-read RentalUnit $unit
 */
#[Fillable(['rental_id', 'rental_unit_id', 'kind', 'hour_meter', 'fuel', 'clean', 'notes', 'user_id'])]
class RentalInspection extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'kind' => InspectionKind::class,
            'fuel' => FuelLevel::class,
            'clean' => 'boolean',
            'hour_meter' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<Rental, $this>
     */
    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    /**
     * @return BelongsTo<RentalUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(RentalUnit::class, 'rental_unit_id');
    }

    /**
     * @return HasMany<InspectionPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(InspectionPhoto::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
