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
            // In good standing for tests that travel weeks ahead; see freshTrial().
            'trial_ends_at' => now('America/Toronto')->addYear()->toDateString(),
            // Most tests exercise rentals; new sign-ups start with it off.
            'rentals_enabled' => true,
        ];
    }

    /**
     * A tenant on day 1 of the 14-day trial a sign-up gets.
     */
    public function freshTrial(): static
    {
        return $this->state(fn () => ['trial_ends_at' => now('America/Toronto')->addDays(13)->toDateString()]);
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
