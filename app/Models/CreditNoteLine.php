<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

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
 * @property int $line_total_cents
 * @property int $tax_cents
 * @property bool $restock
 */
#[Fillable(['credit_note_id', 'order_line_id', 'product_id', 'description', 'quantity', 'unit', 'base_quantity', 'unit_price_cents', 'line_total_cents', 'tax_cents', 'restock'])]
class CreditNoteLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'unit_price_cents' => 'integer',
            'line_total_cents' => 'integer',
            'tax_cents' => 'integer',
            'restock' => 'boolean',
        ];
    }
}
