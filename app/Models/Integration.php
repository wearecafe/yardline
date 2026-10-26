<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A tenant's connection to an outside system (Shopify).
 *
 * @property int $id
 * @property int $tenant_id
 * @property IntegrationProvider $provider
 * @property IntegrationStatus $status
 * @property string|null $external_account
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 */
#[Fillable(['provider', 'status', 'external_account', 'credentials', 'settings', 'last_synced_at', 'last_error'])]
#[Hidden(['credentials'])]
class Integration extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'status' => IntegrationStatus::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === IntegrationStatus::Connected;
    }

    public static function activeFor(IntegrationProvider $provider): ?self
    {
        return static::query()
            ->where('provider', $provider->value)
            ->where('status', IntegrationStatus::Connected->value)
            ->first();
    }
}
