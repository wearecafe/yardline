<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers and purchase orders, receiving, lots (colour runs), transfers
 * between yards, and cycle counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->unsignedSmallInteger('lead_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('supplier_name')->constrained()->nullOnDelete();
            $table->boolean('track_lots')->default(false)->after('track_inventory');
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('location_id')->constrained();
            $table->string('status', 20)->default('draft'); // draft | sent | partial | received | cancelled
            $table->date('expected_on')->nullable();
            $table->string('supplier_reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'expected_on']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 12, 3); // base units
            $table->decimal('received_quantity', 12, 3)->default(0);
            $table->unsignedBigInteger('unit_cost_cents'); // per base unit, landed
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // A lot: a colour run of pavers or block that has to ship together.
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->decimal('on_hand', 14, 3)->default(0);
            $table->date('received_on')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'location_id', 'code']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_lot_id')->nullable()->after('location_id')->constrained()->nullOnDelete();
        });

        Schema::table('shipment_lines', function (Blueprint $table) {
            $table->foreignId('stock_lot_id')->nullable()->after('order_line_id')->constrained()->nullOnDelete();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('from_location_id')->constrained('locations');
            $table->foreignId('to_location_id')->constrained('locations');
            $table->string('status', 20)->default('done');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('stock_lot_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
        });

        Schema::create('cycle_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->string('name');
            $table->string('status', 20)->default('open'); // open | completed | cancelled
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cycle_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('bin', 40)->nullable();
            $table->decimal('expected', 14, 3);
            $table->decimal('counted', 14, 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_count_lines');
        Schema::dropIfExists('cycle_counts');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::table('shipment_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('stock_lot_id'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('stock_lot_id'));
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('track_lots');
        });
        Schema::dropIfExists('suppliers');
    }
};
