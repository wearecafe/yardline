<?php

use App\Http\Controllers\Settings\BusinessController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\QuickBooksController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\ShopifyController;
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

    Route::get('settings/shopify', [ShopifyController::class, 'edit'])->name('shopify.edit');
    Route::put('settings/shopify', [ShopifyController::class, 'update'])->name('shopify.update');
    Route::delete('settings/shopify', [ShopifyController::class, 'destroy'])->name('shopify.destroy');

    Route::get('settings/quickbooks', [QuickBooksController::class, 'edit'])->name('quickbooks.edit');
    Route::post('settings/quickbooks/connect', [QuickBooksController::class, 'connect'])->name('quickbooks.connect');
    Route::get('settings/quickbooks/callback', [QuickBooksController::class, 'callback'])->name('quickbooks.callback');
    Route::put('settings/quickbooks', [QuickBooksController::class, 'update'])->name('quickbooks.update');
    Route::delete('settings/quickbooks', [QuickBooksController::class, 'destroy'])->name('quickbooks.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
