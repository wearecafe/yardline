<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The trucks each driver is able to drive. A driver can be cleared for
 * several trucks, and a truck for several drivers; `trucks.driver_id`
 * stays the one driver who signs in to that truck's run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_truck', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('truck_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'truck_id']);
        });

        // Whoever signs in to a truck today can drive it.
        $now = now();
        DB::table('trucks')->whereNotNull('driver_id')->orderBy('id')->each(function (object $truck) use ($now) {
            DB::table('driver_truck')->insert([
                'tenant_id' => $truck->tenant_id,
                'user_id' => $truck->driver_id,
                'truck_id' => $truck->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_truck');
    }
};
