<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order picking: the Picked stage, quality-check sign-offs at Picked and
 * Loaded, the order picker role's yards, and a PIN for switching pickers
 * on a shared yard tablet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // 'picked' or 'loaded' while the load waits for a sign-off.
            $table->string('check_stage', 20)->nullable()->after('status');
            $table->foreignId('picked_by')->nullable()->after('picked_at')->constrained('users')->nullOnDelete();
            $table->foreignId('loaded_by')->nullable()->after('loaded_at')->constrained('users')->nullOnDelete();
            // When stock left the yard's books and the load went to dispatch.
            $table->timestamp('released_at')->nullable()->after('loaded_by');
        });

        DB::table('shipments')
            ->whereIn('status', ['loaded', 'ready', 'out', 'delivered', 'picked_up'])
            ->update(['released_at' => DB::raw('coalesce(loaded_at, updated_at)')]);

        Schema::create('shipment_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 20); // picked | loaded
            $table->string('outcome', 20); // signed_off | rejected
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Who completed the stage being checked.
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('quantities')->nullable(); // shipment line id => quantity confirmed
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('reason')->nullable(); // why it was rejected
            $table->timestamps();

            $table->index(['shipment_id', 'stage']);
        });

        // The yards an order picker works.
        Schema::create('location_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['location_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('pin')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('pin'));
        Schema::dropIfExists('location_user');
        Schema::dropIfExists('shipment_checks');
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('picked_by');
            $table->dropConstrainedForeignId('loaded_by');
            $table->dropColumn(['check_stage', 'released_at']);
        });
    }
};
