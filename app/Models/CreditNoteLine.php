<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $credit_note_id
 * @property int|null $order_line_id
 * @property int|null $product_id
 * @property string $description
 * @property string $quantity
 * @property string $unit
 * @property string $base_quantity
 * @property int $unit_price_cents
 * @property string|null $unit_cost_cents cost per base unit put back in stock (restocked lines)
 * @property int $line_total_cents
 * @property int $tax_cents
 * @property bool $restock
 * @property-read Product|null $product
 * @property-read CreditNote $creditNote
 */
#[Fillable(['credit_note_id', 'order_line_id', 'product_id', 'description', 'quantity', 'unit', 'base_quantity', 'unit_price_cents', 'unit_cost_cents', 'line_total_cents', 'tax_cents', 'restock'])]
class CreditNoteLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'unit_price_cents' => 'integer',
            'unit_cost_cents' => 'decimal:4',
            'line_total_cents' => 'integer',
            'tax_cents' => 'integer',
            'restock' => 'boolean',
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
     * @return BelongsTo<CreditNote, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }
}
