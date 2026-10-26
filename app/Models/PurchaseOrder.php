<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $number
 * @property int $supplier_id
 * @property int $location_id
 * @property string $status draft | sent | partial | received | cancelled
 * @property CarbonImmutable|null $expected_on
 * @property string|null $supplier_reference
 * @property string|null $notes
 * @property int $total_cents
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $received_at
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Supplier $supplier
 * @property-read Location $location
 * @property-read Collection<int, PurchaseOrderLine> $lines
 */
#[Fillable(['number', 'supplier_id', 'location_id', 'status', 'expected_on', 'supplier_reference', 'notes', 'created_by'])]
class PurchaseOrder extends Model
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'draft', 'total_cents' => 0];

    protected function casts(): array
    {
        return [
            'expected_on' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'total_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('position')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'sent', 'partial'], true);
    }
}
