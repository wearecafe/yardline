<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('kind')->default('sale'); // sale | rental
            $table->string('unit')->default('each');
            // Sale products: price per unit. Rental products: price per rental period.
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->unsignedBigInteger('cost_cents')->nullable();
            $table->string('rental_period')->nullable(); // day | week
            $table->unsignedBigInteger('deposit_cents')->default(0);
            $table->string('tax_code')->nullable(); // Stripe Tax code, e.g. txcd_99999999
            $table->boolean('track_inventory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_quantity', 12, 3)->default(1);
            $table->unsignedBigInteger('price_cents');
            $table->timestamps();

            $table->unique(['price_list_id', 'product_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('products');
    }
};
