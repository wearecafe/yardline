<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drivers who sign in: the truck they usually drive, and where it was last
 * seen from their phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trucks', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('kind')->constrained('users')->nullOnDelete();
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->unsignedInteger('last_accuracy_m')->nullable();
            $table->timestamp('last_located_at')->nullable();
        });

        // Positions sent from a driver's phone while their run is open.
        // Kept for 30 days (DriverPing is prunable).
        Schema::create('driver_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('truck_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->decimal('speed_kph', 5, 1)->nullable();
            $table->timestamp('recorded_at');
            $table->index(['tenant_id', 'user_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_pings');

        Schema::table('trucks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropColumn(['last_latitude', 'last_longitude', 'last_accuracy_m', 'last_located_at']);
        });
    }
};
