<?php

use App\Http\Controllers\Settings\TimeSettingsController;
use App\Http\Controllers\Time\EmployeeController;
use App\Http\Controllers\Time\KioskController;
use App\Http\Controllers\Time\MyTimeController;
use App\Http\Controllers\Time\PayPeriodController;
use App\Http\Controllers\Time\PunchCorrectionController;
use App\Http\Controllers\Time\TimesheetController;
use App\Http\Controllers\Time\WhosInController;
use Illuminate\Support\Facades\Route;

// Time and attendance, once the business turns it on (Settings → Time).
Route::middleware(['auth', 'verified', 'time'])->group(function () {
    // Everyone's own clock and timesheet; drivers reach it from their run.
    Route::controller(MyTimeController::class)->name('time.self.')->group(function () {
        Route::get('time/me', 'show')->name('show');
        Route::post('time/me/punch', 'punch')->middleware('throttle:30,1')->name('punch');
        Route::post('time/me/submit', 'submit')->name('submit');
        Route::post('time/me/adjustments/{adjustment}/dispute', 'dispute')->name('dispute');
    });

    // The counter tablet: tap a name, enter a PIN.
    Route::get('time/kiosk', [KioskController::class, 'show'])->name('time.kiosk');
    Route::post('time/kiosk', [KioskController::class, 'punch'])->middleware('throttle:60,1')->name('time.kiosk.punch');

    Route::middleware('can:manage-time')->group(function () {
        Route::get('time', [WhosInController::class, 'index'])->name('time.board');

        Route::get('time/timesheets', [TimesheetController::class, 'index'])->name('time.timesheets.index');
        Route::get('time/timesheets/{employee}', [TimesheetController::class, 'show'])->name('time.timesheets.show');
        Route::post('time/timesheets/{employee}/approve', [TimesheetController::class, 'approve'])->name('time.timesheets.approve');
        Route::post('time/timesheets/{employee}/unapprove', [TimesheetController::class, 'unapprove'])->name('time.timesheets.unapprove');

        Route::post('time/employees/{employee}/punches', [PunchCorrectionController::class, 'store'])->name('time.punches.store');
        Route::put('time/punches/{punch}', [PunchCorrectionController::class, 'update'])->name('time.punches.update');
        Route::delete('time/punches/{punch}', [PunchCorrectionController::class, 'destroy'])->name('time.punches.destroy');
        Route::post('time/employees/{employee}/hours', [PunchCorrectionController::class, 'hours'])->name('time.hours.store');
        Route::post('time/adjustments/{adjustment}/resolve', [PunchCorrectionController::class, 'resolve'])->name('time.adjustments.resolve');

        Route::post('time/periods/{period}/approve-all', [TimesheetController::class, 'approveAll'])->name('time.periods.approve-all');
        Route::post('time/periods/{period}/lock', [PayPeriodController::class, 'lock'])->name('time.periods.lock');
        Route::post('time/periods/{period}/unlock', [PayPeriodController::class, 'unlock'])->name('time.periods.unlock');
        Route::get('time/periods/{period}/export/{format}', [PayPeriodController::class, 'download'])->whereIn('format', ['summary', 'adp'])->name('time.periods.download');
        Route::post('time/periods/{period}/quickbooks', [PayPeriodController::class, 'quickbooks'])->name('time.periods.quickbooks');

        Route::get('time/employees', [EmployeeController::class, 'index'])->name('time.employees.index');
        Route::post('time/employees', [EmployeeController::class, 'store'])->name('time.employees.store');
        Route::post('time/employees/everyone', [EmployeeController::class, 'addEveryone'])->name('time.employees.everyone');
        Route::put('time/employees/{employee}', [EmployeeController::class, 'update'])->name('time.employees.update');
        Route::post('time/employees/{employee}/archive', [EmployeeController::class, 'archive'])->name('time.employees.archive');
        Route::post('time/employees/{employee}/restore', [EmployeeController::class, 'restore'])->name('time.employees.restore');
    });
});

Route::middleware(['auth', 'verified', 'can:manage-business'])->group(function () {
    Route::get('settings/time', [TimeSettingsController::class, 'edit'])->name('time-settings.edit');
    Route::put('settings/time', [TimeSettingsController::class, 'update'])->name('time-settings.update');
});
