<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment rental, run entirely in YardRunner: serialized units, contracts,
 * agreements, inspections, extra charges, maintenance and payments.
 * Rental products (kind = rental) are the models and rate cards; rental
 * units are the individual machines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // price_cents is the day rate for rental products.
            $table->unsignedBigInteger('rate_hour_cents')->nullable()->after('price_cents');
            $table->unsignedBigInteger('rate_half_day_cents')->nullable()->after('rate_hour_cents');
            $table->unsignedBigInteger('rate_week_cents')->nullable()->after('rate_half_day_cents');
            $table->unsignedBigInteger('rate_month_cents')->nullable()->after('rate_week_cents');
            $table->unsignedBigInteger('rate_weekend_cents')->nullable()->after('rate_month_cents');
            $table->unsignedBigInteger('minimum_charge_cents')->default(0)->after('rate_weekend_cents');
            $table->unsignedBigInteger('fuel_charge_cents')->default(0)->after('minimum_charge_cents');
            $table->unsignedBigInteger('cleaning_fee_cents')->default(0)->after('fuel_charge_cents');
            $table->unsignedInteger('service_interval_hours')->nullable()->after('cleaning_fee_cents');
            $table->unsignedInteger('service_interval_days')->nullable()->after('service_interval_hours');
            $table->string('use_case')->nullable()->after('description');
            $table->boolean('bookable_online')->default(false)->after('is_active');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('stripe_payment_method_id')->nullable()->after('stripe_customer_id');
            $table->string('card_brand', 20)->nullable()->after('stripe_payment_method_id');
            $table->string('card_last4', 4)->nullable()->after('card_brand');
        });

        // Driver's licence, insurance certificate and the like, kept on file.
        Schema::create('customer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // drivers_licence | insurance_certificate
            $table->string('reference', 40)->nullable(); // e.g. last 4 of a licence number
            $table->date('expires_on')->nullable();
            $table->string('path')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'kind']);
        });

        // Delivery fees by zone; used by rentals and goods deliveries.
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('postal_prefixes')->nullable();
            $table->unsignedBigInteger('fee_cents');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('rental_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->string('serial', 40);
            $table->string('status')->default('available'); // available | in_shop | retired
            $table->decimal('hour_meter', 10, 1)->nullable();
            $table->unsignedInteger('service_interval_hours')->nullable();
            $table->unsignedInteger('service_interval_days')->nullable();
            $table->decimal('last_service_hours', 10, 1)->nullable();
            $table->date('last_service_on')->nullable();
            $table->date('purchased_on')->nullable();
            $table->unsignedBigInteger('purchase_cost_cents')->nullable();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'serial']);
            $table->index(['tenant_id', 'product_id', 'status']);
        });

        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            // quote | reserved | out | returned | cancelled  (overdue is derived)
            $table->string('status')->default('quote');
            $table->string('source')->default('counter'); // counter | phone | online
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('fulfillment')->default('pickup'); // pickup | delivery
            $table->json('delivery_address')->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('damage_waiver')->default(true);
            $table->decimal('damage_waiver_percent', 5, 2)->default(0);
            $table->boolean('bill_fuel_cleaning')->default(true);
            $table->boolean('text_reminders')->default(true);

            $table->unsignedBigInteger('rental_cents')->default(0);
            $table->unsignedBigInteger('waiver_cents')->default(0);
            $table->unsignedBigInteger('delivery_fee_cents')->default(0);
            $table->unsignedBigInteger('charges_cents')->default(0); // added on return
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('amount_paid_cents')->default(0);
            $table->string('tax_calculation_ref')->nullable();
            $table->string('tax_transaction_ref')->nullable();

            $table->unsignedBigInteger('deposit_cents')->default(0);
            // none | held | released | captured
            $table->string('deposit_status')->default('none');
            $table->unsignedBigInteger('deposit_captured_cents')->default(0);
            // card_on_file | pay_now | payment_link | counter
            $table->string('payment_method')->default('card_on_file');

            $table->string('signing_token', 64)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'ends_at']);
        });

        Schema::create('rental_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('rental_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('rate_summary')->nullable(); // "1 week", "3 days"
            $table->unsignedBigInteger('charge_cents')->default(0);
            $table->unsignedBigInteger('day_rate_equivalent_cents')->default(0);
            $table->unsignedBigInteger('deposit_cents')->default(0);
            $table->decimal('hours_out', 10, 1)->nullable();
            $table->decimal('hours_in', 10, 1)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'rental_unit_id']);
        });

        Schema::create('rental_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->longText('terms');
            $table->json('summary');
            $table->string('signer_name')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->boolean('acknowledged_training')->default(false);
            $table->foreignId('id_document_id')->nullable()->constrained('customer_documents')->nullOnDelete();
            $table->string('signed_via')->nullable(); // counter | link
            $table->string('signed_ip', 45)->nullable();
            $table->text('signed_user_agent')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rental_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_unit_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // check_out | return
            $table->decimal('hour_meter', 10, 1)->nullable();
            $table->string('fuel', 10)->nullable(); // full | half | low
            $table->boolean('clean')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inspection_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inspection_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('label')->nullable();
            $table->boolean('new_damage')->default(false);
            $table->timestamps();
        });

        Schema::create('rental_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind'); // fuel | cleaning | damage | late | other
            $table->string('description');
            $table->unsignedBigInteger('amount_cents');
            $table->boolean('covered_by_waiver')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_unit_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // service | repair
            $table->string('status')->default('open'); // open | done
            $table->string('description');
            $table->decimal('hour_meter', 10, 1)->nullable();
            $table->unsignedBigInteger('cost_cents')->default(0);
            $table->foreignId('rental_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'rental_unit_id', 'status']);
        });

        Schema::create('rental_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            // charge | deposit_hold | deposit_release | deposit_capture | refund
            $table->string('kind');
            $table->string('tender'); // card_on_file | payment_link | card_terminal | cash | cheque | etransfer
            $table->string('status')->default('pending'); // pending | succeeded | failed | cancelled
            $table->unsignedBigInteger('amount_cents');
            $table->string('reference')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'rental_id', 'kind']);
        });

        // One row per reminder sent, so the scheduler never sends twice.
        Schema::create('rental_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // confirmation | pickup_reminder | return_reminder | overdue | receipt | signing_link
            $table->string('channel'); // mail | sms
            $table->timestamp('sent_at');

            $table->index(['rental_id', 'kind']);
        });
    }

    public function down(): void
    {
        foreach ([
            'rental_notifications', 'rental_payments', 'maintenance_records',
            'rental_charges', 'inspection_photos', 'rental_inspections',
            'rental_agreements', 'rental_items', 'rentals', 'rental_units',
            'delivery_zones', 'customer_documents',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['stripe_payment_method_id', 'card_brand', 'card_last4']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'rate_hour_cents', 'rate_half_day_cents', 'rate_week_cents', 'rate_month_cents',
                'rate_weekend_cents', 'minimum_charge_cents', 'fuel_charge_cents', 'cleaning_fee_cents',
                'service_interval_hours', 'service_interval_days', 'use_case', 'bookable_online',
            ]);
        });
    }
};
