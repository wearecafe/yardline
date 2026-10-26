<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // shopify
            $table->string('status')->default('connected');
            $table->string('external_account')->nullable(); // e.g. shop domain
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->json('settings')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
            $table->index(['provider', 'external_account']);
        });

        // Maps Yardline records to their IDs in a connected system.
        Schema::create('external_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->morphs('syncable');
            $table->string('external_id');
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'syncable_type', 'syncable_id']);
            $table->unique(['tenant_id', 'provider', 'syncable_type', 'external_id'], 'external_refs_lookup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_references');
        Schema::dropIfExists('integrations');
    }
};
