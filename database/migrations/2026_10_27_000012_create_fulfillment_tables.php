<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Picking, partial shipments, trucks and the dispatch board, proof of
 * delivery, and delivery fees on goods orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trucks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('kind', 30)->default('material'); // material | equipment
            $table->string('driver_name')->nullable();
            $table->string('driver_phone', 30)->nullable();
            $table->unsignedInteger('capacity_kg')->nullable();
            $table->boolean('has_boom')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Where a product lives in each yard: "Bay 4", "Row C".
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->string('bin', 40)->nullable()->after('location_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('fulfillment_status', 20)->nullable()->after('payment_status');
            $table->foreignId('delivery_zone_id')->nullable()->after('delivery_address')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('delivery_fee_cents')->default(0)->after('delivery_zone_id');
            $table->boolean('delivery_fee_manual')->default(false)->after('delivery_fee_cents');
            $table->decimal('delivery_km', 8, 1)->nullable()->after('delivery_fee_manual');
            $table->unsignedSmallInteger('delivery_loads')->nullable()->after('delivery_km');
            $table->decimal('load_weight_kg', 12, 1)->nullable()->after('delivery_loads');
            $table->boolean('needs_boom')->default(false)->after('load_weight_kg');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->decimal('shipped_quantity', 12, 3)->default(0)->after('base_quantity');
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('method', 20); // pickup | delivery
            $table->string('status', 20)->default('picking'); // picking | loaded | ready | out | delivered | picked_up | cancelled
            $table->foreignId('picker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('loaded_at')->nullable();
            $table->decimal('weight_kg', 12, 1)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('pod_name')->nullable();
            $table->string('pod_signature_path')->nullable();
            $table->string('pod_photo_path')->nullable();
            $table->text('pod_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('shipment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 3); // to pick, in the line's unit
            $table->decimal('picked_quantity', 12, 3)->nullable(); // what was actually loaded
            $table->boolean('short')->default(false);
            $table->string('note')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamps();
        });

        // A truck run on the dispatch board: a material delivery or a rental
        // drop-off or pickup.
        Schema::create('stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->morphs('stoppable'); // shipment | rental
            $table->string('kind', 20); // delivery | rental_drop | rental_pickup
            $table->foreignId('truck_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date')->nullable();
            $table->time('starts_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('status', 20)->default('unscheduled'); // unscheduled | scheduled | en_route | done | cancelled
            $table->timestamp('eta_at')->nullable();
            $table->timestamp('eta_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('address')->nullable();
            $table->string('needs')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'date', 'truck_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stops');
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('shipments');
        Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn('shipped_quantity'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_zone_id');
            $table->dropColumn(['fulfillment_status', 'delivery_fee_cents', 'delivery_fee_manual', 'delivery_km', 'delivery_loads', 'load_weight_kg', 'needs_boom']);
        });
        Schema::table('stock_levels', fn (Blueprint $table) => $table->dropColumn('bin'));
        Schema::dropIfExists('trucks');
    }
};
