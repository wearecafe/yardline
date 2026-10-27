<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify is no longer supported: YardRunner is the only sales channel.
 * Removes Shopify connections, the ID map used to sync with it, and the
 * per-unit Shopify variant title.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('integrations')->where('provider', 'shopify')->delete();
        DB::table('orders')->where('source', 'shopify')->update(['source' => 'yardline']);

        Schema::dropIfExists('external_references');

        if (Schema::hasColumn('product_units', 'variant_title')) {
            Schema::table('product_units', function (Blueprint $table) {
                $table->dropColumn('variant_title');
            });
        }
    }

    public function down(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->string('variant_title', 100)->nullable();
        });

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
};
