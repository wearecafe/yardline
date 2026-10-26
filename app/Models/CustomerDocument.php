<?php

namespace App\Models;

use App\Enums\DocumentKind;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property DocumentKind $kind
 * @property string|null $reference
 * @property CarbonImmutable|null $expires_on
 * @property string|null $path
 * @property int|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['customer_id', 'kind', 'reference', 'expires_on', 'path', 'verified_by', 'verified_at'])]
class CustomerDocument extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'expires_on' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isCurrent(): bool
    {
        return $this->expires_on === null || ! $this->expires_on->isPast();
    }
}
