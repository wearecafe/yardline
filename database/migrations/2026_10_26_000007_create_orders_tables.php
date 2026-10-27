<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('next_value')->default(1001);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('type')->default('sale'); // sale | rental
            $table->string('status')->default('draft');
            $table->string('source')->default('yardline'); // yardline | counter | portal
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->string('fulfillment')->default('pickup'); // pickup | delivery
            $table->json('delivery_address')->nullable();
            $table->date('requested_on')->nullable();
            $table->string('po_number')->nullable();

            // Settlement: account (charged against the org's credit) or prepaid (card/cash up front).
            $table->string('settlement')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->foreignId('credit_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('credit_override_reason')->nullable();

            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('deposit_cents')->default(0);
            $table->unsignedBigInteger('amount_paid_cents')->default(0);
            $table->string('tax_calculation_ref')->nullable();
            $table->string('tax_transaction_ref')->nullable();

            $table->timestamp('rental_starts_at')->nullable();
            $table->timestamp('rental_ends_at')->nullable();
            $table->timestamp('returned_at')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'organization_id', 'payment_status']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 64)->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 3);
            $table->string('unit')->default('each');
            $table->unsignedBigInteger('unit_price_cents');
            $table->boolean('price_overridden')->default(false);
            $table->unsignedBigInteger('line_total_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('deposit_cents')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('tender'); // stripe_checkout | card_terminal | cash | cheque | etransfer
            $table->string('status')->default('pending'); // pending | succeeded | failed
            $table->unsignedBigInteger('amount_cents');
            $table->string('reference')->nullable();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('sequences');
    }
};
