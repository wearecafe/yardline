<?php

namespace App\Billing;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Demo;
use Carbon\CarbonImmutable;

/**
 * Where a tenant stands with billing right now, and whether they can use
 * YardRunner. Worked out from the trial end date and the subscription
 * state Stripe last sent, so nothing has to run for a trial to end.
 *
 * - Trial: the trial end date hasn't passed. Everything works, add-ons too.
 * - Active, or past due while Stripe retries the card: everything works.
 * - Trial ended with no subscription, unpaid after retries, cancelled, or
 *   suspended by staff: locked. Owners and admins get the billing page.
 */
final readonly class BillingState
{
    /** Stripe statuses that keep the tenant working. */
    public const WORKING = ['trialing', 'active', 'past_due'];

    public function __construct(
        public TenantStatus $status,
        public bool $hasAccess,
        public bool $subscribed,
        public bool $exempt,
        public ?CarbonImmutable $trialEndsAt,
        public ?int $trialDaysLeft,
        public ?CarbonImmutable $periodEndsAt,
        public bool $cancelling,
    ) {}

    public static function for(Tenant $tenant, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now($tenant->timezone ?: 'America/Toronto');
        $today = $now->startOfDay();
        // A date with no time: the last day of the trial, in the yard's time zone.
        $trialEnds = $tenant->trial_ends_at === null
            ? null
            : CarbonImmutable::parse($tenant->trial_ends_at->toDateString(), $now->getTimezone())->startOfDay();
        $inTrial = $trialEnds !== null && $trialEnds->greaterThanOrEqualTo($today);
        $stripe = $tenant->subscription_status;
        $subscribed = $tenant->stripe_subscription_id !== null && in_array($stripe, self::WORKING, true);
        $exempt = Demo::tenantId() === $tenant->id;

        $status = match (true) {
            $tenant->suspended_at !== null => TenantStatus::Suspended,
            $stripe === 'active' => TenantStatus::Active,
            $stripe === 'past_due' => TenantStatus::PastDue,
            $stripe === 'trialing' => TenantStatus::Trial,
            $stripe === 'unpaid' => TenantStatus::Suspended,
            $stripe === 'canceled' && ! $inTrial => TenantStatus::Cancelled,
            $inTrial => TenantStatus::Trial,
            default => TenantStatus::Expired,
        };

        $hasAccess = $exempt || in_array($status, [TenantStatus::Trial, TenantStatus::Active, TenantStatus::PastDue], true);

        return new self(
            status: $exempt && $tenant->suspended_at === null ? TenantStatus::Active : $status,
            hasAccess: $hasAccess,
            subscribed: $subscribed,
            exempt: $exempt,
            trialEndsAt: $trialEnds,
            // Through the end of the trial day, so the last day counts as 1.
            trialDaysLeft: $inTrial ? (int) $today->diffInDays($trialEnds) + 1 : null,
            periodEndsAt: $tenant->subscription_period_ends_at,
            cancelling: $subscribed && $tenant->subscription_cancels_at_period_end,
        );
    }

    /**
     * The moment the trial ends: midnight after its last day, in the
     * yard's time zone. Null without a trial end date.
     */
    public static function trialEndsAtMoment(Tenant $tenant): ?CarbonImmutable
    {
        if ($tenant->trial_ends_at === null) {
            return null;
        }

        return CarbonImmutable::parse($tenant->trial_ends_at->toDateString(), $tenant->timezone ?: 'America/Toronto')
            ->addDay()
            ->startOfDay();
    }

    /**
     * Whether the trial is still running.
     */
    public function inTrial(): bool
    {
        return $this->trialDaysLeft !== null;
    }
}
