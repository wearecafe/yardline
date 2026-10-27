<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pallet usage fees: a non-refundable charge on each pallet a product ships
 * on, alongside (or instead of) the refundable pallet deposit. The tenant
 * turns it on and sets the amount in tenants.settings['returns']; each yard
 * and each product turns it on or off for itself.
 *
 * The fee is revenue, taxed like the product it ships with. It is never
 * credited when the empty pallet comes back, only when unused product comes
 * back on full pallets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('pallet_usage_fee')->default(false)->after('pallet_deposit');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('pallet_usage_fees')->default(true)->after('pallet_deposits');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->unsignedInteger('fee_pallets')->default(0)->after('pallets');
            $table->unsignedBigInteger('pallet_fee_cents')->default(0)->after('deposit_cents');
            $table->unsignedBigInteger('pallet_fee_tax_cents')->default(0)->after('pallet_fee_cents');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('pallet_fee_cents')->default(0)->after('deposit_cents');
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->unsignedBigInteger('pallet_fee_cents')->default(0)->after('deposit_cents');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', fn (Blueprint $table) => $table->dropColumn('pallet_fee_cents'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('pallet_fee_cents'));
        Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn(['fee_pallets', 'pallet_fee_cents', 'pallet_fee_tax_cents']));
        Schema::table('locations', fn (Blueprint $table) => $table->dropColumn('pallet_usage_fees'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('pallet_usage_fee'));
    }
};
