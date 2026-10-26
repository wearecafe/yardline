<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free, per-tenant counters for human-facing numbers (order numbers).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property int $next_value
 */
#[Fillable(['name', 'next_value'])]
class Sequence extends Model
{
    use BelongsToTenant;

    public static function next(string $name): int
    {
        return DB::transaction(function () use ($name) {
            $sequence = static::query()->where('name', $name)->lockForUpdate()->first()
                ?? static::query()->create(['name' => $name, 'next_value' => 1001]);

            $value = $sequence->next_value;
            $sequence->increment('next_value');

            return $value;
        });
    }
}
