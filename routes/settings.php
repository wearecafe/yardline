<?php

use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\BusinessController;
use App\Http\Controllers\Settings\DriverAccountsController;
use App\Http\Controllers\Settings\OrderPickingController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\QuickBooksController;
use App\Http\Controllers\Settings\SecurityController;
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
});

Route::middleware(['auth', 'verified', 'can:manage-business'])->group(function () {
    Route::get('settings/business', [BusinessController::class, 'edit'])->name('business.edit');
    Route::patch('settings/business', [BusinessController::class, 'update'])->name('business.update');

    Route::get('settings/drivers', [DriverAccountsController::class, 'index'])->name('drivers.index');
    Route::post('settings/drivers', [DriverAccountsController::class, 'store'])->name('drivers.store');
    Route::put('settings/drivers/{driver}', [DriverAccountsController::class, 'update'])->name('drivers.update');
    Route::post('settings/drivers/{driver}/resend', [DriverAccountsController::class, 'resend'])->name('drivers.resend');
    Route::delete('settings/drivers/{driver}', [DriverAccountsController::class, 'destroy'])->name('drivers.destroy');

    Route::get('settings/picking', [OrderPickingController::class, 'edit'])->name('picking-settings.edit');
    Route::put('settings/picking', [OrderPickingController::class, 'update'])->name('picking-settings.update');

    Route::get('settings/quickbooks', [QuickBooksController::class, 'edit'])->name('quickbooks.edit');
    Route::post('settings/quickbooks/connect', [QuickBooksController::class, 'connect'])->name('quickbooks.connect');
    Route::get('settings/quickbooks/callback', [QuickBooksController::class, 'callback'])->name('quickbooks.callback');
    Route::put('settings/quickbooks', [QuickBooksController::class, 'update'])->name('quickbooks.update');
    Route::delete('settings/quickbooks', [QuickBooksController::class, 'destroy'])->name('quickbooks.destroy');
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
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
