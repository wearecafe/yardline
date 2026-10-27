<?php

use App\Http\Controllers\Admin\IntegrationHealthController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\TenantController;
use Illuminate\Support\Facades\Route;

// The back office, for YardRunner staff only (see EnsurePlatformStaff).
Route::middleware(['auth', 'verified', 'back-office'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', OverviewController::class)->name('overview');

    Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::patch('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::post('tenants/{tenant}/users/{user}/password-link', [TenantController::class, 'sendPasswordLink'])
        ->middleware('throttle:10,1')
        ->name('tenants.users.password-link');

    Route::get('reports', ReportController::class)->name('reports');

    Route::get('integrations', [IntegrationHealthController::class, 'index'])->name('integrations');
    Route::post('integrations/quickbooks/{tenant}/retry', [IntegrationHealthController::class, 'retryQuickBooks'])->name('integrations.quickbooks.retry');
    Route::post('integrations/jobs/{uuid}/retry', [IntegrationHealthController::class, 'retryJob'])->whereUuid('uuid')->name('integrations.jobs.retry');
    Route::delete('integrations/jobs/{uuid}', [IntegrationHealthController::class, 'forgetJob'])->whereUuid('uuid')->name('integrations.jobs.forget');
});
