<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property list<string>|null $postal_prefixes
 * @property int $fee_cents
 * @property int $position
 */
#[Fillable(['name', 'postal_prefixes', 'fee_cents', 'position'])]
class DeliveryZone extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'postal_prefixes' => 'array',
            'fee_cents' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * The zone whose postal prefix best matches (longest prefix wins).
     */
    public static function forPostalCode(?string $postalCode): ?self
    {
        $code = Str::upper(str_replace(' ', '', (string) $postalCode));

        if ($code === '') {
            return null;
        }

        $best = null;
        $bestLength = 0;

        foreach (static::query()->orderBy('position')->get() as $zone) {
            foreach ($zone->postal_prefixes ?? [] as $prefix) {
                $prefix = Str::upper(str_replace(' ', '', $prefix));

                if ($prefix !== '' && str_starts_with($code, $prefix) && strlen($prefix) > $bestLength) {
                    $best = $zone;
                    $bestLength = strlen($prefix);
                }
            }
        }

        return $best;
    }
}
