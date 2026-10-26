<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $rental_id
 * @property string $kind
 * @property string $channel
 * @property CarbonImmutable $sent_at
 */
#[Fillable(['rental_id', 'kind', 'channel', 'sent_at'])]
class RentalNotification extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
