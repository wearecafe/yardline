<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Yardline record that has been (or tried to be) sent
        // to QuickBooks: where it stands, the last error, and what was sent.
        Schema::create('quickbooks_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('syncable_type', 40);
            $table->unsignedBigInteger('syncable_id');
            $table->string('label')->nullable();
            $table->string('status', 20); // synced | waiting | skipped | failed
            $table->string('qbo_type', 40)->nullable();
            $table->string('qbo_id', 40)->nullable();
            $table->text('message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'syncable_type', 'syncable_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_syncs');
    }
};
