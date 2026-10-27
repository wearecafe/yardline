<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web push: each phone, tablet or browser where someone turned on alerts.
 *
 * The endpoint is the push service's URL for that device. It's long, so
 * it's matched on a SHA-256 hash; one device belongs to one user at a time
 * (signing in as someone else on a shared tablet moves it to them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('device')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
