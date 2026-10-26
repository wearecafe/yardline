<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\FakeCheckoutController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\OrderActionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderLineController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\PriceListItemController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\Webhooks\ShopifyWebhookController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Support\Demo;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', fn () => Inertia::render('welcome', [
    'demo' => Demo::available(),
    'canRegister' => Features::enabled(Features::registration()),
]))->name('home');

// One-click sign-in to the demo yard (404 unless YARDLINE_DEMO=true).
Route::middleware('guest')->group(function () {
    Route::get('demo', [DemoLoginController::class, 'show'])->name('demo');
    Route::post('demo', [DemoLoginController::class, 'login'])->middleware('throttle:20,1')->name('demo.login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Orders
    Route::resource('orders', OrderController::class)->except(['edit']);

    Route::scopeBindings()->group(function () {
        Route::post('orders/{order}/lines', [OrderLineController::class, 'store'])->name('orders.lines.store');
        Route::patch('orders/{order}/lines/{line}', [OrderLineController::class, 'update'])->name('orders.lines.update');
        Route::delete('orders/{order}/lines/{line}', [OrderLineController::class, 'destroy'])->name('orders.lines.destroy');
    });

    Route::controller(OrderActionController::class)->prefix('orders/{order}')->name('orders.')->group(function () {
        Route::post('confirm', 'confirm')->name('confirm');
        Route::post('fulfill', 'fulfill')->name('fulfill');
        Route::post('return', 'returnRental')->name('return');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::post('payments', 'recordPayment')->name('payments.store');
        Route::post('payment-link', 'paymentLink')->name('payment-link');
    });

    // Accounts
    Route::resource('organizations', OrganizationController::class)->except(['destroy']);
    Route::resource('customers', CustomerController::class)->except(['show', 'destroy']);

    // Catalog and pricing
    Route::resource('products', ProductController::class)->except(['show', 'destroy']);

    Route::resource('price-lists', PriceListController::class)
        ->only(['index', 'store', 'edit', 'update'])
        ->parameters(['price-lists' => 'priceList']);
    Route::post('price-lists/{priceList}/items', [PriceListItemController::class, 'store'])->name('price-lists.items.store');
    Route::delete('price-lists/{priceList}/items/{item}', [PriceListItemController::class, 'destroy'])
        ->scopeBindings()
        ->name('price-lists.items.destroy');

    // Inventory
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('inventory/adjustments', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.store');
    Route::resource('locations', LocationController::class)->only(['index', 'store', 'update']);

    // Local development only (the controller 404s otherwise).
    Route::get('dev/fake-checkout/{session}', FakeCheckoutController::class)->name('dev.fake-checkout');
});

Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
Route::post('webhooks/shopify', ShopifyWebhookController::class)->name('webhooks.shopify');

require __DIR__.'/settings.php';
require __DIR__.'/rentals.php';
