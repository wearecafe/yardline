<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling units that can be switched off for sale (a base unit stock is
 * counted in but never sold on its own, like single pavers), and how a
 * product rounds a quantity that doesn't fit its sellable units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->boolean('sellable')->default(true)->after('is_base');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('quantity_rounding', 10)->default('off')->after('per_pallet'); // off | up | down
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('quantity_rounding');
        });

        Schema::table('product_units', function (Blueprint $table) {
            $table->dropColumn('sellable');
        });
    }
};
