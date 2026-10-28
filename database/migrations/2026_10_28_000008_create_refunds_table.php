<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money paid back out on an order payment or a credit note: to the card
 * through Stripe, or handed back by hand. A refund on a credit note leaves
 * the order's payments alone; it only uses up what can still be refunded
 * on the card. Refunds made in Stripe itself arrive by webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_note_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tender'); // same values as payments.tender
            $table->string('status')->default('pending'); // pending | succeeded | failed
            $table->string('source')->default('yardrunner'); // yardrunner | stripe
            $table->unsignedBigInteger('amount_cents');
            // YardRunner's application fee given back to the yard with a card
            // refund, so the refund doesn't cost the yard YardRunner's fee.
            $table->unsignedBigInteger('fee_returned_cents')->default(0);
            $table->string('reason')->nullable();
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->string('failure_message')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            // Everything refunded against this payment, including refunds
            // of credit notes paid back onto its card.
            $table->unsignedBigInteger('refunded_cents')->default(0)->after('amount_cents');
            $table->index('stripe_payment_intent_id');
        });

        Schema::table('rental_payments', function (Blueprint $table) {
            // On a card refund, YardRunner's application fee given back to the yard.
            $table->unsignedBigInteger('fee_returned_cents')->default(0)->after('fee_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_payments', function (Blueprint $table) {
            $table->dropColumn('fee_returned_cents');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['stripe_payment_intent_id']);
            $table->dropColumn('refunded_cents');
        });

        Schema::dropIfExists('refunds');
    }
};
