<?php

namespace App\Orders;

use App\Enums\OrderType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateOrder
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $user): Order
    {
        return DB::transaction(function () use ($attributes, $user) {
            $type = OrderType::from($attributes['type'] ?? OrderType::Sale->value);

            // A contact implies their organization when none was picked.
            if (empty($attributes['organization_id']) && ! empty($attributes['customer_id'])) {
                $attributes['organization_id'] = Customer::query()->whereKey($attributes['customer_id'])->value('organization_id');
            }

            return Order::query()->create([
                ...$attributes,
                'type' => $type,
                'number' => $type->numberPrefix().'-'.Sequence::next('orders'),
                'created_by' => $user->id,
            ]);
        });
    }
}
