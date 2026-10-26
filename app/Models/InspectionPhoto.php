<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $rental_inspection_id
 * @property string $path
 * @property string|null $label
 * @property bool $new_damage
 */
#[Fillable(['rental_inspection_id', 'path', 'label', 'new_damage'])]
class InspectionPhoto extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['new_damage' => 'boolean'];
    }

    /**
     * @return BelongsTo<RentalInspection, $this>
     */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }
}
