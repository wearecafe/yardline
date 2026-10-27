<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supply-yard business using YardRunner.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $currency
 * @property string $timezone
 * @property string|null $stripe_account_id
 * @property array<string, mixed>|null $settings
 * @property TenantStatus $status
 * @property CarbonImmutable|null $status_changed_at
 * @property string|null $plan
 * @property int|null $monthly_price_cents
 * @property CarbonImmutable|null $trial_ends_at
 * @property array<string, bool>|null $modules
 * @property string|null $staff_notes
 * @property CarbonImmutable|null $last_active_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name', 'slug', 'currency', 'timezone', 'stripe_account_id', 'settings',
    'status', 'plan', 'monthly_price_cents', 'trial_ends_at', 'modules', 'staff_notes',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'status' => TenantStatus::class,
            'status_changed_at' => 'datetime',
            'trial_ends_at' => 'immutable_date',
            'modules' => 'array',
            'last_active_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Tenant $tenant) {
            if ($tenant->isDirty('status')) {
                $tenant->status_changed_at = now();
            }
        });
    }

    /**
     * Whether staff have a module switched on. Modules are on unless
     * switched off. Recorded only: nothing is hidden from the tenant yet.
     */
    public function hasModule(string $module): bool
    {
        return (bool) ($this->modules[$module] ?? true);
    }

    public function planLabel(): ?string
    {
        if ($this->plan === null) {
            return null;
        }

        $label = config("yardline.back_office.plans.{$this->plan}");

        return is_string($label) ? $label : $this->plan;
    }

    /**
     * Where customer replies to quote and rental emails should go.
     */
    public function replyToEmail(): ?string
    {
        $email = $this->settings['reply_to_email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
