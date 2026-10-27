<?php

namespace App\Models;

use App\Billing\BillingState;
use App\Enums\TenantStatus;
use App\Time\TimeSettings;
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
 * @property CarbonImmutable|null $trial_ends_at last day of the free trial
 * @property bool $rentals_enabled the Rentals add-on
 * @property string|null $stripe_customer_id on YardRunner's own Stripe account, for billing
 * @property string|null $stripe_subscription_id
 * @property string|null $subscription_status Stripe's: trialing, active, past_due, unpaid, canceled…
 * @property CarbonImmutable|null $subscription_period_ends_at
 * @property bool $subscription_cancels_at_period_end
 * @property CarbonImmutable|null $subscription_synced_at
 * @property int|null $billed_seats Time & attendance people on the subscription
 * @property CarbonImmutable|null $suspended_at by YardRunner staff
 * @property string|null $staff_notes
 * @property CarbonImmutable|null $last_active_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name', 'slug', 'currency', 'timezone', 'stripe_account_id', 'settings',
    'status', 'trial_ends_at', 'rentals_enabled', 'staff_notes',
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
            'rentals_enabled' => 'boolean',
            'subscription_period_ends_at' => 'immutable_datetime',
            'subscription_cancels_at_period_end' => 'boolean',
            'subscription_synced_at' => 'immutable_datetime',
            'billed_seats' => 'integer',
            'suspended_at' => 'immutable_datetime',
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
     * Whether the tenant has a paid add-on switched on. Everything else is
     * part of the base subscription.
     */
    public function hasModule(string $module): bool
    {
        return match ($module) {
            'rentals' => (bool) $this->rentals_enabled,
            'time' => TimeSettings::for($this)->enabled,
            default => true,
        };
    }

    public function billing(): BillingState
    {
        return BillingState::for($this);
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
