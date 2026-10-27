<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone, tablet or browser where someone turned on alerts (web push).
 * The keys encrypt each alert for that device only.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property string|null $device
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['tenant_id', 'user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding', 'device'])]
#[Hidden(['public_key', 'auth_token'])]
class PushSubscription extends Model
{
    use BelongsToTenant;

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
