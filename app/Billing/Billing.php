<?php

namespace App\Billing;

use App\Jobs\SyncSubscription;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Keeps a tenant's billing columns in step with their add-ons, tracked
 * people and Stripe subscription.
 */
final class Billing
{
    /**
     * Recalculate the plan, monthly price and status shown in the back
     * office. Cheap; safe to call after any change.
     */
    public static function refresh(Tenant $tenant): void
    {
        $plan = Plan::for($tenant);

        $tenant->forceFill([
            'plan' => $plan->key(),
            'monthly_price_cents' => $plan->monthlyCents(),
            'status' => BillingState::for($tenant)->status,
        ]);

        if ($tenant->isDirty()) {
            $tenant->save();
        }
    }

    /**
     * The tenant switched an add-on on or off, or the number of people
     * whose time they track changed: reprice, and update Stripe if they
     * already have a subscription.
     */
    public static function changed(Tenant $tenant): void
    {
        self::refresh($tenant);

        if ($tenant->stripe_subscription_id !== null && in_array($tenant->subscription_status, BillingState::WORKING, true)) {
            SyncSubscription::dispatch($tenant->id);
        }
    }

    /**
     * Someone's "tracks time" changed, or they were added or archived.
     */
    public static function seatsChanged(int $tenantId): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant !== null && Plan::for($tenant)->time) {
            self::changed($tenant);
        }
    }

    /**
     * Copy a subscription Stripe sent (a customer.subscription.* event)
     * onto the tenant. Returns false when it's older than what's already
     * been applied, or belongs to a subscription the tenant has replaced.
     *
     * @param  array<string, mixed>  $subscription
     */
    public static function apply(Tenant $tenant, array $subscription, int $eventCreated): bool
    {
        $id = (string) ($subscription['id'] ?? '');
        $status = (string) ($subscription['status'] ?? '');
        $finished = in_array($status, ['canceled', 'incomplete_expired'], true);

        if ($id === '') {
            return false;
        }

        if ($tenant->stripe_subscription_id === $id
            && $tenant->subscription_synced_at !== null
            && $eventCreated < $tenant->subscription_synced_at->getTimestamp()) {
            return false;
        }

        // An old subscription ending after they started a new one.
        if ($tenant->stripe_subscription_id !== null && $tenant->stripe_subscription_id !== $id && $finished) {
            return false;
        }

        $items = self::items($subscription);
        $periodEnd = $subscription['current_period_end'] ?? ($subscription['items']['data'][0]['current_period_end'] ?? null);
        $timezone = $tenant->timezone ?: 'America/Toronto';

        $tenant->forceFill([
            'stripe_subscription_id' => $id,
            'stripe_customer_id' => is_string($subscription['customer'] ?? null) ? $subscription['customer'] : $tenant->stripe_customer_id,
            'subscription_status' => $status,
            'subscription_period_ends_at' => is_int($periodEnd) ? CarbonImmutable::createFromTimestamp($periodEnd) : null,
            'subscription_cancels_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false) || ($subscription['cancel_at'] ?? null) !== null,
            'subscription_synced_at' => CarbonImmutable::createFromTimestamp($eventCreated),
            'billed_seats' => $items[Plan::TIME] ?? 0,
        ]);

        // A trial moved in Stripe (or bumped to meet Checkout's 48-hour
        // minimum) is the trial: its last day is the day before it ends.
        if ($status === 'trialing' && is_int($subscription['trial_end'] ?? null)) {
            $tenant->setAttribute('trial_ends_at', CarbonImmutable::createFromTimestamp($subscription['trial_end'] - 1, $timezone)->toDateString());
        }

        $tenant->save();
        self::refresh($tenant);

        return true;
    }

    /**
     * Quantity of each of our items on a subscription.
     *
     * @param  array<string, mixed>  $subscription
     * @return array<string, int>
     */
    public static function items(array $subscription): array
    {
        $quantities = [];
        $data = $subscription['items']['data'] ?? [];

        foreach (is_array($data) ? $data : [] as $item) {
            $key = Plan::keyForLookup($item['price']['lookup_key'] ?? null);

            if ($key !== null) {
                $quantities[$key] = ($quantities[$key] ?? 0) + (int) ($item['quantity'] ?? 0);
            }
        }

        return $quantities;
    }
}
