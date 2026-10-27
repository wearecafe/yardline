<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The back office, where Yardline staff look after tenants.
 *
 * - Staff are users with no tenant and is_platform_staff set.
 * - Each tenant has a status, plan and monthly price that staff record by
 *   hand (no subscription billing yet), module switches (stored, not yet
 *   enforced) and private staff notes.
 * - Activity: when each user was last seen, one row per user per day they
 *   used Yardline (for weekly users and retention), and a log of incoming
 *   webhooks for integration health.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_staff')->default(false)->after('role');
            $table->timestamp('last_seen_at')->nullable()->after('remember_token');
        });

        Schema::table('tenants', function (Blueprint $table) {
            // trial | active | past_due | suspended | cancelled
            $table->string('status', 20)->default('active')->after('slug');
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->string('plan', 40)->nullable()->after('status_changed_at');
            $table->unsignedInteger('monthly_price_cents')->nullable()->after('plan');
            $table->date('trial_ends_at')->nullable()->after('monthly_price_cents');
            $table->json('modules')->nullable()->after('settings');
            $table->text('staff_notes')->nullable()->after('modules');
            $table->timestamp('last_active_at')->nullable()->after('staff_notes');

            $table->index('status');
        });

        Schema::create('user_activity_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');

            $table->unique(['user_id', 'day']);
            $table->index(['tenant_id', 'day']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20); // shopify | stripe
            $table->string('topic', 80)->nullable();
            $table->string('outcome', 20); // accepted | ignored | rejected | failed
            $table->string('detail')->nullable();
            $table->timestamp('received_at');

            $table->index(['provider', 'received_at']);
            $table->index(['tenant_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('user_activity_days');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status', 'status_changed_at', 'plan', 'monthly_price_cents', 'trial_ends_at',
                'modules', 'staff_notes', 'last_active_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_platform_staff', 'last_seen_at']);
        });
    }
};
