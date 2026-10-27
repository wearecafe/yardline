<?php

use App\Http\Controllers\SetupController;
use App\Onboarding\Setup;
use Illuminate\Support\Facades\Route;

// Setup for a new business: its details and yards (required), then the
// optional steps. Owners and admins are kept here until the first two are
// saved (EnsureSetUp). See docs/scope/tenant-setup.md.
Route::middleware(['auth', 'verified', 'can:manage-business'])
    ->controller(SetupController::class)
    ->prefix('setup')
    ->name('setup.')
    ->group(function () {
        $steps = implode('|', array_keys(Setup::STEPS));

        Route::get('/', 'index')->name('index');
        Route::put('business', 'business')->name('business');
        Route::put('yards', 'yards')->name('yards');
        Route::put('work', 'work')->name('work');
        Route::post('team', 'invite')->middleware('throttle:30,1')->name('team');
        Route::put('brand', 'brand')->name('brand');
        Route::post('finish', 'finish')->name('finish');
        Route::post('checklist/hide', 'hideChecklist')->name('checklist.hide');
        Route::post('{step}/next', 'next')->where('step', $steps)->name('next');
        Route::get('{step}', 'show')->where('step', $steps)->name('step');
    });
