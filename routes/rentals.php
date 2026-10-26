<?php

use App\Http\Controllers\CustomerDocumentController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Rentals\AgreementController;
use App\Http\Controllers\Rentals\BookingController;
use App\Http\Controllers\Rentals\CalendarController;
use App\Http\Controllers\Rentals\FakeRentalCheckoutController;
use App\Http\Controllers\Rentals\FleetController;
use App\Http\Controllers\Rentals\InspectionController;
use App\Http\Controllers\Rentals\MaintenanceController;
use App\Http\Controllers\Rentals\RentalController;
use App\Http\Controllers\Rentals\RentalPaymentController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\Settings\AuditController;
use App\Http\Controllers\Settings\DeliverySettingsController;
use App\Http\Controllers\Settings\RentalSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Equipment rental: the whole rental, payments included, lives in Yardline.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('rentals/preview', [RentalController::class, 'preview'])->name('rentals.preview');
    Route::resource('rentals', RentalController::class)->only(['index', 'create', 'store', 'show', 'update']);

    Route::controller(RentalController::class)->prefix('rentals/{rental}')->name('rentals.')->group(function () {
        Route::post('reserve', 'reserve')->name('reserve');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::post('signing-link', 'sendSigningLink')->name('signing-link');
        Route::post('charge-card', 'chargeCard')->name('charge-card');
        Route::post('payment-link', 'paymentLink')->name('payment-link');
        Route::post('payments', 'recordPayment')->name('payments.store');
        Route::patch('move', 'move')->name('move');
    });

    Route::get('rentals/{rental}/agreement', [AgreementController::class, 'show'])->name('rentals.agreement');
    Route::post('rentals/{rental}/agreement', [AgreementController::class, 'sign'])->name('rentals.agreement.sign');
    Route::post('rentals/{rental}/agreement/pay', [AgreementController::class, 'pay'])->name('rentals.agreement.pay');

    Route::get('rentals/{rental}/inspect/{kind}', [InspectionController::class, 'show'])->whereIn('kind', ['check-out', 'return'])->name('rentals.inspect');
    Route::post('rentals/{rental}/inspect/{kind}', [InspectionController::class, 'store'])->whereIn('kind', ['check-out', 'return'])->name('rentals.inspect.store');

    Route::get('fleet', [FleetController::class, 'index'])->name('fleet.index');
    Route::get('fleet/units/create', [FleetController::class, 'create'])->name('fleet.units.create');
    Route::post('fleet/units', [FleetController::class, 'store'])->name('fleet.units.store');
    Route::get('fleet/units/{unit}', [FleetController::class, 'show'])->name('fleet.units.show');
    Route::put('fleet/units/{unit}', [FleetController::class, 'update'])->name('fleet.units.update');

    Route::get('rental-calendar', CalendarController::class)->name('rentals.calendar');

    Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');
    Route::post('maintenance/{record}/complete', [MaintenanceController::class, 'complete'])->name('maintenance.complete');

    Route::get('rental-payments', RentalPaymentController::class)->name('rental-payments.index');

    Route::post('customers/{customer}/documents', [CustomerDocumentController::class, 'store'])->name('customers.documents.store');
    Route::delete('customers/{customer}/documents/{document}', [CustomerDocumentController::class, 'destroy'])->scopeBindings()->name('customers.documents.destroy');

    Route::get('files/{path}', FileController::class)->where('path', '.*')->name('files.show');
});

Route::middleware(['auth', 'verified', 'can:manage-business'])->group(function () {
    Route::get('settings/rentals', [RentalSettingsController::class, 'edit'])->name('rental-settings.edit');
    Route::put('settings/rentals', [RentalSettingsController::class, 'update'])->name('rental-settings.update');
    Route::post('settings/delivery-zones', [RentalSettingsController::class, 'storeZone'])->name('delivery-zones.store');
    Route::put('settings/delivery-zones/{zone}', [RentalSettingsController::class, 'updateZone'])->name('delivery-zones.update');
    Route::delete('settings/delivery-zones/{zone}', [RentalSettingsController::class, 'destroyZone'])->name('delivery-zones.destroy');

    Route::get('settings/delivery', [DeliverySettingsController::class, 'edit'])->name('delivery-settings.edit');
    Route::get('settings/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::put('settings/returns', [ReturnController::class, 'settings'])->name('returns-settings.update');
    Route::put('settings/delivery', [DeliverySettingsController::class, 'update'])->name('delivery-settings.update');
    Route::post('settings/trucks', [DeliverySettingsController::class, 'storeTruck'])->name('trucks.store');
    Route::put('settings/trucks/{truck}', [DeliverySettingsController::class, 'updateTruck'])->name('trucks.update');
    Route::delete('settings/trucks/{truck}', [DeliverySettingsController::class, 'destroyTruck'])->name('trucks.destroy');
});

// Public: the customer's signing link and the yard's booking page.
Route::get('sign/{token}', [AgreementController::class, 'publicShow'])->name('rentals.public-sign');
Route::post('sign/{token}', [AgreementController::class, 'publicSign'])->middleware('throttle:10,1')->name('rentals.public-sign.store');
Route::post('sign/{token}/pay', [AgreementController::class, 'publicPay'])->middleware('throttle:10,1')->name('rentals.public-sign.pay');

Route::get('book/{tenant:slug}', [BookingController::class, 'show'])->name('book.show');
Route::get('book/{tenant:slug}/availability', [BookingController::class, 'availability'])->middleware('throttle:60,1')->name('book.availability');
Route::get('book/{tenant:slug}/quote', [BookingController::class, 'quote'])->middleware('throttle:60,1')->name('book.quote');
Route::post('book/{tenant:slug}', [BookingController::class, 'store'])->middleware('throttle:10,1')->name('book.store');

// Local development and the demo yard only (the controller 404s otherwise).
Route::get('dev/rental-checkout/{session}', FakeRentalCheckoutController::class)->name('rentals.fake-checkout');
