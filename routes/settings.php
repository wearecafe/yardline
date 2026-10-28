<?php

use App\Http\Controllers\Settings\AlertsController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\BrandingController;
use App\Http\Controllers\Settings\BusinessController;
use App\Http\Controllers\Settings\DriverAccountsController;
use App\Http\Controllers\Settings\ImportController;
use App\Http\Controllers\Settings\OrderPickingController;
use App\Http\Controllers\Settings\PaymentsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\QuickBooksController;
use App\Http\Controllers\Settings\RoleSettingsController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SendingDomainController;
use App\Http\Controllers\Settings\UserAccountsController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // Alerts on this phone or tablet (web push).
    Route::get('settings/alerts', [AlertsController::class, 'edit'])->name('alerts.edit');
    Route::post('settings/alerts/devices', [AlertsController::class, 'store'])->middleware('throttle:30,1')->name('alerts.devices.store');
    Route::delete('settings/alerts/devices', [AlertsController::class, 'destroy'])->name('alerts.devices.destroy');
    Route::post('settings/alerts/test', [AlertsController::class, 'test'])->middleware('throttle:6,1')->name('alerts.test');
});

Route::middleware(['auth', 'verified', 'can:manage-business'])->group(function () {
    Route::get('settings/business', [BusinessController::class, 'edit'])->name('business.edit');
    Route::patch('settings/business', [BusinessController::class, 'update'])->name('business.update');

    // How the yard looks on customer emails.
    Route::get('settings/branding', [BrandingController::class, 'edit'])->name('branding.edit');
    Route::put('settings/branding', [BrandingController::class, 'update'])->name('branding.update');
    Route::post('settings/branding/logo', [BrandingController::class, 'storeLogo'])->name('branding.logo.store');
    Route::delete('settings/branding/logo', [BrandingController::class, 'destroyLogo'])->name('branding.logo.destroy');
    Route::get('settings/branding/preview', [BrandingController::class, 'preview'])->middleware('throttle:120,1')->name('branding.preview');
    Route::post('settings/branding/test', [BrandingController::class, 'test'])->middleware('throttle:6,1')->name('branding.test');

    // Sending customer emails from the yard's own domain.
    Route::get('settings/sending-domain', [SendingDomainController::class, 'edit'])->name('sending-domain.edit');
    Route::post('settings/sending-domain', [SendingDomainController::class, 'store'])->middleware('throttle:10,1')->name('sending-domain.store');
    Route::patch('settings/sending-domain', [SendingDomainController::class, 'update'])->name('sending-domain.update');
    Route::post('settings/sending-domain/check', [SendingDomainController::class, 'check'])->middleware('throttle:12,1')->name('sending-domain.check');
    Route::post('settings/sending-domain/records', [SendingDomainController::class, 'sendRecords'])->middleware('throttle:6,1')->name('sending-domain.records');
    Route::delete('settings/sending-domain', [SendingDomainController::class, 'destroy'])->name('sending-domain.destroy');

    // Loading a yard's existing data from spreadsheets.
    Route::get('settings/import', [ImportController::class, 'index'])->name('imports.index');
    Route::get('settings/import/templates/{kind}', [ImportController::class, 'template'])->name('imports.template');
    Route::get('settings/import/samples/{kind}', [ImportController::class, 'sample'])->name('imports.sample');
    Route::post('settings/import', [ImportController::class, 'store'])->middleware('throttle:30,1')->name('imports.store');
    Route::get('settings/import/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('settings/import/{import}/run', [ImportController::class, 'run'])->middleware('throttle:30,1')->name('imports.run');
    Route::get('settings/import/{import}/errors', [ImportController::class, 'errors'])->name('imports.errors');
    Route::delete('settings/import/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');

    Route::get('settings/drivers', [DriverAccountsController::class, 'index'])->name('drivers.index');
    Route::post('settings/drivers', [DriverAccountsController::class, 'store'])->name('drivers.store');
    Route::put('settings/drivers/{driver}', [DriverAccountsController::class, 'update'])->name('drivers.update');
    Route::post('settings/drivers/{driver}/resend', [DriverAccountsController::class, 'resend'])->name('drivers.resend');
    Route::delete('settings/drivers/{driver}', [DriverAccountsController::class, 'destroy'])->name('drivers.destroy');

    Route::get('settings/picking', [OrderPickingController::class, 'edit'])->name('picking-settings.edit');
    Route::put('settings/picking', [OrderPickingController::class, 'update'])->name('picking-settings.update');

    // Card payments on the yard's connected account (Stripe Connect).
    Route::get('settings/payments', [PaymentsController::class, 'edit'])->name('payments.edit');
    Route::post('settings/payments', [PaymentsController::class, 'store'])->middleware('throttle:6,1')->name('payments.store');
    Route::post('settings/payments/session', [PaymentsController::class, 'session'])->middleware('throttle:30,1')->name('payments.session');
    Route::post('settings/payments/refresh', [PaymentsController::class, 'refresh'])->middleware('throttle:30,1')->name('payments.refresh');
    Route::post('settings/payments/simulate', [PaymentsController::class, 'simulate'])->name('payments.simulate');

    Route::post('settings/quickbooks/connect', [QuickBooksController::class, 'connect'])->name('quickbooks.connect');
    Route::get('settings/quickbooks/callback', [QuickBooksController::class, 'callback'])->name('quickbooks.callback');
    Route::put('settings/quickbooks', [QuickBooksController::class, 'update'])->name('quickbooks.update');
    Route::delete('settings/quickbooks', [QuickBooksController::class, 'destroy'])->name('quickbooks.destroy');
});

// The QuickBooks page and its sync log: Accounts fixes sync errors too.
Route::middleware(['auth', 'verified', 'can:quickbooks-log'])->group(function () {
    Route::get('settings/quickbooks', [QuickBooksController::class, 'edit'])->name('quickbooks.edit');
    Route::post('settings/quickbooks/log/{sync}/resend', [QuickBooksController::class, 'resend'])->name('quickbooks.resend');
});

// The business's YardRunner subscription. Reachable while the account is
// paused (see EnsureBilling), so owners and admins can subscribe.
Route::middleware(['auth', 'verified', 'can:manage-business'])->group(function () {
    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');
    Route::put('settings/billing/add-ons', [BillingController::class, 'addOns'])->name('billing.add-ons');
    Route::post('settings/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('billing.checkout');
    Route::get('settings/billing/subscribed', [BillingController::class, 'subscribed'])->name('billing.subscribed');
    Route::post('settings/billing/portal', [BillingController::class, 'portal'])->middleware('throttle:10,1')->name('billing.portal');
});

Route::get('account-paused', [BillingController::class, 'paused'])->middleware(['auth'])->name('billing.paused');

Route::middleware(['auth', 'verified', 'can:manage-users'])->group(function () {
    Route::get('settings/users', [UserAccountsController::class, 'index'])->name('users.index');
    Route::post('settings/users', [UserAccountsController::class, 'store'])->name('users.store');
    Route::patch('settings/users/{user}', [UserAccountsController::class, 'update'])->name('users.update');
    Route::post('settings/users/{user}/resend', [UserAccountsController::class, 'resend'])->name('users.resend');
    Route::delete('settings/users/{user}', [UserAccountsController::class, 'destroy'])->name('users.destroy');
    Route::put('settings/users/reports', [UserAccountsController::class, 'reports'])->name('users.reports');

    // What each role gives by default, and refund limits.
    Route::get('settings/roles', [RoleSettingsController::class, 'edit'])->name('roles.edit');
    Route::put('settings/roles', [RoleSettingsController::class, 'update'])->name('roles.update');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
