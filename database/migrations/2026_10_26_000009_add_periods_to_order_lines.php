<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental lines charge units × periods (days or weeks) × rate.
     * Sale lines always have one period.
     */
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->unsignedSmallInteger('periods')->default(1)->after('unit_price_cents');
            $table->string('period_unit', 10)->nullable()->after('periods'); // day | week, rentals only
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn(['periods', 'period_unit']);
        });
    }
};
