<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Card payments run on a connected account YardRunner creates for each
     * yard (Stripe Connect, no Stripe dashboard). Account IDs typed in by
     * hand are cleared: every yard sets up a new account in Settings,
     * Payments.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('stripe_charges_enabled')->default(false)->after('stripe_account_id');
            $table->boolean('stripe_payouts_enabled')->default(false)->after('stripe_charges_enabled');
            $table->boolean('stripe_details_submitted')->default(false)->after('stripe_payouts_enabled');
            $table->json('stripe_requirements')->nullable()->after('stripe_details_submitted');
            $table->timestamp('stripe_account_synced_at')->nullable()->after('stripe_requirements');
        });

        DB::table('tenants')->update(['stripe_account_id' => null]);

        // Saved customers and cards lived on the old accounts. Rental
        // customers add their card again on their next booking.
        DB::table('customers')->update([
            'stripe_customer_id' => null,
            'stripe_payment_method_id' => null,
            'card_brand' => null,
            'card_last4' => null,
        ]);
        DB::table('organizations')->update(['stripe_customer_id' => null]);

        // Webhooks find the yard by its account.
        Schema::table('tenants', function (Blueprint $table) {
            $table->unique('stripe_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['stripe_account_id']);
            $table->dropColumn([
                'stripe_charges_enabled',
                'stripe_payouts_enabled',
                'stripe_details_submitted',
                'stripe_requirements',
                'stripe_account_synced_at',
            ]);
        });
    }
};
