<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drivers belong to people, not trucks: a driver's phone number lives on
 * their user, and who drives a truck is picked per day on the dispatch
 * board. Trucks get a list of capacities (weight, volume, pallets) and
 * can carry material, rental equipment or both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
        });

        Schema::create('truck_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('truck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamps();
            $table->unique(['truck_id', 'date']);
            $table->index(['tenant_id', 'user_id', 'date']);
        });

        Schema::table('trucks', function (Blueprint $table) {
            $table->json('capacities')->nullable()->after('kind');
        });

        $now = now();
        $today = $now->toDateString();

        DB::table('trucks')->orderBy('id')->each(function (object $truck) use ($now, $today) {
            if ($truck->capacity_kg) {
                DB::table('trucks')->where('id', $truck->id)->update([
                    'capacities' => json_encode([['unit' => 't', 'amount' => round($truck->capacity_kg / 1000, 3)]]),
                ]);
            }

            if ($truck->driver_id) {
                // Whoever signed in to the truck keeps today's run.
                DB::table('truck_assignments')->insert([
                    'tenant_id' => $truck->tenant_id,
                    'truck_id' => $truck->id,
                    'user_id' => $truck->driver_id,
                    'date' => $today,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($truck->driver_phone) {
                    DB::table('users')->where('id', $truck->driver_id)->whereNull('phone')->update(['phone' => $truck->driver_phone]);
                }
            }
        });

        Schema::table('trucks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropColumn(['driver_name', 'driver_phone', 'capacity_kg']);
        });
    }

    public function down(): void
    {
        Schema::table('trucks', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('kind')->constrained('users')->nullOnDelete();
            $table->string('driver_name')->nullable();
            $table->string('driver_phone')->nullable();
            $table->unsignedInteger('capacity_kg')->nullable();
            $table->dropColumn('capacities');
        });

        Schema::dropIfExists('truck_assignments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
