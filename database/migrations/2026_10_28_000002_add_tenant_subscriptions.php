<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenants pay for YardRunner by subscription, through Stripe Billing on
 * YardRunner's own Stripe account (not the yard's Connect account).
 *
 * - The base subscription, plus Rentals and Time & attendance (per tracked
 *   person) as add-ons the tenant switches on themselves.
 * - The subscription's state is copied from Stripe's webhooks; the plan and
 *   monthly price are worked out from what the tenant has switched on.
 * - Staff can suspend a tenant by hand, separately from billing.
 * - The module switches staff kept in the back office are gone. Existing
 *   tenants keep rentals unless staff had switched them off, and get a
 *   fresh 14-day trial to subscribe in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('rentals_enabled')->default(false)->after('settings');
            $table->string('stripe_customer_id')->nullable()->after('stripe_account_id');
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id');
            // Stripe's status: trialing | active | past_due | unpaid | canceled | incomplete | …
            $table->string('subscription_status', 30)->nullable()->after('stripe_subscription_id');
            $table->timestamp('subscription_period_ends_at')->nullable()->after('subscription_status');
            $table->boolean('subscription_cancels_at_period_end')->default(false)->after('subscription_period_ends_at');
            // Time of the last Stripe event applied, so a late one can't undo a newer one.
            $table->timestamp('subscription_synced_at')->nullable()->after('subscription_cancels_at_period_end');
            $table->unsignedInteger('billed_seats')->nullable()->after('subscription_synced_at');
            $table->timestamp('suspended_at')->nullable()->after('status_changed_at');

            $table->index('stripe_customer_id');
            $table->index('stripe_subscription_id');
        });

        $fresh = CarbonImmutable::now('America/Toronto')->addDays(13)->toDateString();

        DB::table('tenants')->orderBy('id')->each(function (object $tenant) use ($fresh) {
            $modules = is_string($tenant->modules) ? json_decode($tenant->modules, true) : null;
            $rentals = ! is_array($modules) || ($modules['rentals'] ?? true) !== false;
            $trialEnds = $tenant->trial_ends_at !== null && (string) $tenant->trial_ends_at > $fresh
                ? substr((string) $tenant->trial_ends_at, 0, 10)
                : $fresh;

            DB::table('tenants')->where('id', $tenant->id)->update(match ($tenant->status) {
                'cancelled' => ['rentals_enabled' => $rentals],
                'suspended' => ['rentals_enabled' => $rentals, 'trial_ends_at' => $trialEnds, 'suspended_at' => now()],
                default => ['rentals_enabled' => $rentals, 'trial_ends_at' => $trialEnds, 'status' => 'trial'],
            });
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('settings');
        });

        DB::table('tenants')->where('rentals_enabled', false)->update(['modules' => json_encode(['rentals' => false])]);

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['stripe_customer_id']);
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropColumn([
                'rentals_enabled', 'stripe_customer_id', 'stripe_subscription_id', 'subscription_status',
                'subscription_period_ends_at', 'subscription_cancels_at_period_end', 'subscription_synced_at',
                'billed_seats', 'suspended_at',
            ]);
        });
    }
};
