<?php

namespace App\Models;

use App\Enums\RentalChargeKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charge added on return: fuel, cleaning, damage, late return.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $rental_id
 * @property int|null $rental_unit_id
 * @property RentalChargeKind $kind
 * @property string $description
 * @property int $amount_cents
 * @property bool $covered_by_waiver
 * @property int|null $created_by
 */
#[Fillable(['rental_id', 'rental_unit_id', 'kind', 'description', 'amount_cents', 'covered_by_waiver', 'created_by'])]
class RentalCharge extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'kind' => RentalChargeKind::class,
            'amount_cents' => 'integer',
            'covered_by_waiver' => 'boolean',
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
     * What the customer actually pays for this charge.
     */
    public function billableCents(): int
    {
        return $this->covered_by_waiver ? 0 : $this->amount_cents;
    }
}
