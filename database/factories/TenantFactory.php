<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company().' Supply';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'currency' => 'CAD',
            'timezone' => 'America/Toronto',
            'trial_ends_at' => now('America/Toronto')->addDays(13)->toDateString(), // a fresh 14-day trial
            // Most tests exercise rentals; new sign-ups start with it off.
            'rentals_enabled' => true,
        ];
    }

    /**
     * A tenant whose free trial ended without a subscription.
     */
    public function trialEnded(): static
    {
        return $this->state(fn () => ['trial_ends_at' => now('America/Toronto')->subDay()->toDateString()]);
    }

    /**
     * A paying tenant with a (fake) Stripe subscription.
     */
    public function subscribed(string $status = 'active'): static
    {
        return $this->state(fn () => [
            'stripe_customer_id' => 'cus_test_'.Str::lower(Str::random(10)),
            'stripe_subscription_id' => 'sub_test_'.Str::lower(Str::random(10)),
            'subscription_status' => $status,
            'subscription_period_ends_at' => now()->addMonth(),
        ]);
    }
}
