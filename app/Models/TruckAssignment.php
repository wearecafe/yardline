<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who drives a truck on a given day, picked on the dispatch board. That
 * driver sees the truck's run on their phone.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $truck_id
 * @property int $user_id
 * @property string $date Y-m-d, in the yard's time zone
 * @property-read Truck $truck
 * @property-read User $driver
 */
#[Fillable(['truck_id', 'user_id', 'date'])]
class TruckAssignment extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<Truck, $this>
     */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
