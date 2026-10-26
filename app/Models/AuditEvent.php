<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $customer_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $subject_label
 * @property string $event
 * @property array<string, array{0: mixed, 1: mixed}>|null $changes
 * @property string|null $note
 * @property string|null $ip
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 * @property-read Customer|null $customer
 */
#[Fillable(['user_id', 'customer_id', 'subject_type', 'subject_id', 'subject_label', 'event', 'changes', 'note', 'ip'])]
class AuditEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
