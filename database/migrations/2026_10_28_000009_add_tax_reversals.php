<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe Tax reversals: the whole sale when an order is cancelled, the
 * credited part when a credit note is issued, so Stripe's reports match
 * the tax actually kept. Credit lines keep their share of pallet usage
 * fees so those can be reversed against the right sale line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tax_reversal_ref')->nullable()->after('tax_transaction_ref');
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('tax_reversal_ref')->nullable()->after('tax_cents');
        });

        Schema::table('credit_note_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('pallet_fee_cents')->default(0)->after('tax_cents');
            $table->unsignedBigInteger('pallet_fee_tax_cents')->default(0)->after('pallet_fee_cents');
        });
    }

    public function down(): void
    {
        Schema::table('credit_note_lines', function (Blueprint $table) {
            $table->dropColumn(['pallet_fee_cents', 'pallet_fee_tax_cents']);
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn('tax_reversal_ref');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tax_reversal_ref');
        });
    }
};
