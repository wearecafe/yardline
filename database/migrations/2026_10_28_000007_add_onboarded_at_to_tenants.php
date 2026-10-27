<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a business finished the required part of Setup (its details and its
 * yards). Until then, owners and admins are kept in Setup. Businesses that
 * already exist are counted as set up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('onboarded_at')->nullable()->after('status_changed_at');
        });

        DB::table('tenants')->update(['onboarded_at' => DB::raw('created_at')]);
        DB::table('tenants')->whereNull('onboarded_at')->update(['onboarded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('onboarded_at');
        });
    }
};
