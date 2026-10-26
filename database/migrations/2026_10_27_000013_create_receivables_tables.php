<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices and receivables, returns and credit notes, and pallet deposits.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One invoice per goods order, issued at confirmation and due by the
        // account's terms. Amounts live on the order.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('terms', 30);
            $table->date('issued_on');
            $table->date('due_on');
            $table->string('status', 20)->default('open'); // open | paid | void
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'organization_id', 'status']);
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('kind', 20); // return | pallets | adjustment
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('restocking_fee_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('deposit_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('applied_cents')->default(0);
            $table->unsignedBigInteger('refunded_cents')->default(0);
            $table->string('refund_tender', 30)->nullable();
            $table->string('status', 20)->default('open'); // open | used
            $table->date('issued_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'organization_id', 'status']);
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 60);
            $table->decimal('base_quantity', 14, 3)->default(0);
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('line_total_cents');
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->boolean('restock')->default(true);
            $table->timestamps();
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->decimal('returned_quantity', 12, 3)->default(0)->after('shipped_quantity');
            $table->unsignedSmallInteger('pallets')->default(0)->after('deposit_cents');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('pallet_deposit')->default(false)->after('deposit_cents');
            $table->decimal('per_pallet', 12, 3)->nullable()->after('pallet_deposit'); // base units on one pallet
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('pallet_deposits')->default(true);
        });

        // Pallets out on deposit: + when they leave on an order, − when they come back.
        Schema::create('pallet_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_note_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantity');
            $table->bigInteger('deposit_cents');
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'organization_id']);
        });

        // Invoices for orders already confirmed.
        $orders = DB::table('orders')->whereIn('status', ['confirmed', 'fulfilled', 'returned'])->where('type', 'sale')->orderBy('id')->get();
        $next = [];

        foreach ($orders as $order) {
            $tenant = $order->tenant_id;
            $next[$tenant] ??= 1001;
            $terms = $order->organization_id && $order->settlement === 'account'
                ? (string) DB::table('organizations')->where('id', $order->organization_id)->value('payment_terms')
                : 'due_on_receipt';
            $issued = substr((string) ($order->confirmed_at ?? $order->created_at), 0, 10);
            $days = ['net_15' => 15, 'net_30' => 30, 'net_60' => 60][$terms] ?? 0;

            DB::table('invoices')->insert([
                'tenant_id' => $tenant,
                'number' => 'INV-'.$next[$tenant]++,
                'order_id' => $order->id,
                'organization_id' => $order->organization_id,
                'customer_id' => $order->customer_id,
                'terms' => $terms,
                'issued_on' => $issued,
                'due_on' => date('Y-m-d', strtotime($issued." +{$days} days")),
                'status' => $order->payment_status === 'paid' ? 'paid' : 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($next as $tenant => $value) {
            DB::table('sequences')->updateOrInsert(['tenant_id' => $tenant, 'name' => 'invoices'], ['next_value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pallet_movements');
        Schema::table('locations', fn (Blueprint $table) => $table->dropColumn('pallet_deposits'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['pallet_deposit', 'per_pallet']));
        Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn(['returned_quantity', 'pallets']));
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('invoices');
    }
};
