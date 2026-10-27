<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A yard's own email domain, so customer emails come from its addresses
 * instead of YardRunner's. One per tenant.
 *
 * `records` are what the yard adds to its DNS (all pointing at
 * yardrunner.ca); `provider_records` are the email provider's real records,
 * which YardRunner publishes on its own DNS and never shows the yard.
 * `mailboxes` maps each mail group (main, accounts, orders, delivery,
 * rentals) to the part before the @.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sending_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('status', 20)->default('pending');
            $table->string('provider_id')->nullable();
            $table->json('records')->nullable();
            $table->json('provider_records')->nullable();
            $table->json('mailboxes')->nullable();
            $table->boolean('sending_enabled')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('failing_since')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('checks_until')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sending_domains');
    }
};
