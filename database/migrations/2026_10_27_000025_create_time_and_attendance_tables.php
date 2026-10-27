<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time and attendance: who tracks time, every punch as it happened,
 * corrections kept beside the punches they correct, pay periods,
 * timesheets and payroll exports.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Where each yard is, so phone punches can be checked against it.
        Schema::table('locations', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('geofence_m')->nullable();
        });

        // Anyone who clocks in. Most are users; yard hands who only punch at
        // the counter tablet don't need a sign-in.
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('job_title')->nullable();
            $table->string('pay_type')->default('hourly');
            $table->boolean('tracks_time')->default(true);
            $table->boolean('overtime_exempt')->default(false);
            $table->boolean('take_home_truck')->default(false);
            $table->boolean('photo_on_punch')->default(false);
            $table->string('payroll_id')->nullable();
            $table->string('pin_hash')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'archived_at']);
        });

        Schema::create('time_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // add, change or remove a punch, or hours added or taken off a day.
            $table->string('action');
            $table->string('punch_type')->nullable();
            $table->timestamp('old_at')->nullable();
            $table->timestamp('new_at')->nullable();
            $table->date('work_date')->nullable();
            $table->integer('minutes')->nullable();
            $table->boolean('overtime')->default(false);
            $table->string('reason', 500);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('disputed_at')->nullable();
            $table->string('dispute_note', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'work_date']);
        });

        // Never edited. A correction supersedes a punch and, for an add or a
        // change, writes a new one that points back at the adjustment.
        Schema::create('time_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->timestamp('punched_at');
            $table->string('source');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            // inside, outside, unknown (no position), or null when not checked.
            $table->string('geofence')->nullable();
            // Set when a phone sent the punch late, from its offline queue.
            $table->timestamp('device_at')->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('adjustment_id')->nullable()->constrained('time_adjustments')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_adjustment_id')->nullable()->constrained('time_adjustments')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'employee_id', 'punched_at']);
        });

        Schema::table('time_adjustments', function (Blueprint $table) {
            $table->foreignId('time_punch_id')->nullable()->after('employee_id')->constrained('time_punches')->nullOnDelete();
        });

        Schema::create('pay_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('open');
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'starts_on']);
        });

        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('open');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            // Frozen when approved; what goes to payroll.
            $table->unsignedInteger('regular_minutes')->nullable();
            $table->unsignedInteger('overtime_minutes')->nullable();
            $table->unsignedInteger('break_minutes')->nullable();
            $table->timestamps();

            $table->unique(['pay_period_id', 'employee_id']);
        });

        Schema::create('payroll_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained()->cascadeOnDelete();
            // summary, adp or quickbooks
            $table->string('format');
            $table->unsignedInteger('employees');
            $table->unsignedInteger('regular_minutes');
            $table->unsignedInteger('overtime_minutes');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            // QuickBooks: employee IDs already sent, so a retry doesn't send them twice.
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_exports');
        Schema::dropIfExists('timesheets');
        Schema::dropIfExists('pay_periods');

        Schema::table('time_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('time_punch_id');
        });

        Schema::dropIfExists('time_punches');
        Schema::dropIfExists('time_adjustments');
        Schema::dropIfExists('employees');

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'geofence_m']);
        });
    }
};
