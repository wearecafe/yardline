<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every employee is a user. Yard hands who only punch at the counter
 * tablet become users with the Time & attendance only role and no email,
 * so they never sign in. An employee's name is their user's name, and
 * YardRunner no longer keeps job titles: roles say what someone does.
 *
 * Users can be deactivated instead of deleted, so the hours of people who
 * have left stay on file with their name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Time & attendance only users may have no email (no sign-in).
            $table->string('email')->nullable()->change();
            $table->timestamp('deactivated_at')->nullable()->after('last_seen_at');
        });

        $yards = DB::table('locations')->get(['id', 'tenant_id'])->groupBy('tenant_id');

        DB::table('employees')->whereNull('user_id')->orderBy('id')->get()->each(function (object $employee) use ($yards) {
            $userId = DB::table('users')->insertGetId([
                'tenant_id' => $employee->tenant_id,
                'name' => $employee->name,
                'email' => null,
                'password' => Hash::make(Str::random(40)),
                'role' => 'time_only',
                'roles' => json_encode(['time_only']),
                'deactivated_at' => $employee->archived_at,
                'created_at' => $employee->created_at ?? now(),
                'updated_at' => now(),
            ]);

            // Their home yard, or every yard when they didn't have one.
            $ids = $employee->location_id !== null
                ? [$employee->location_id]
                : $yards->get($employee->tenant_id, collect())->pluck('id')->all();

            foreach ($ids as $locationId) {
                DB::table('location_user')->insertOrIgnore([
                    'tenant_id' => $employee->tenant_id,
                    'location_id' => $locationId,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('employees')->where('id', $employee->id)->update(['user_id' => $userId]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->dropColumn(['name', 'job_title']);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('name')->default('')->after('location_id');
            $table->string('job_title')->nullable()->after('name');
        });

        DB::table('employees')->orderBy('id')->get(['id', 'user_id'])->each(function (object $employee) {
            DB::table('employees')->where('id', $employee->id)->update([
                'name' => (string) DB::table('users')->where('id', $employee->user_id)->value('name'),
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
