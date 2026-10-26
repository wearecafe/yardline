<?php

use App\Http\Controllers\ContractPriceController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\FakeCheckoutController;
use App\Http\Controllers\FulfillmentController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\OrderActionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderLineController;
use App\Http\Controllers\OrganizationContactController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationLocationController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\PriceListItemController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductUnitController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockLevelController;
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
        Route::post('quote', [QuoteController::class, 'send'])->name('quote');
    });

    // Accounts
    Route::resource('organizations', OrganizationController::class)->except(['destroy']);
    Route::resource('customers', CustomerController::class)->except(['show', 'destroy']);

    // Catalog and pricing
    Route::resource('products', ProductController::class)->except(['show', 'destroy']);
    Route::put('products/{product}/units', [ProductUnitController::class, 'sync'])->name('products.units.sync');

    Route::scopeBindings()->prefix('organizations/{organization}')->name('organizations.')->group(function () {
        Route::post('locations', [OrganizationLocationController::class, 'store'])->name('locations.store');
        Route::put('locations/{site}', [OrganizationLocationController::class, 'update'])->name('locations.update');
        Route::delete('locations/{site}', [OrganizationLocationController::class, 'destroy'])->name('locations.destroy');
        Route::post('contract-prices', [ContractPriceController::class, 'store'])->name('contract-prices.store');
        Route::delete('contract-prices/{contractPrice}', [ContractPriceController::class, 'destroy'])->name('contract-prices.destroy');
        Route::post('contacts', [OrganizationContactController::class, 'store'])->name('contacts.store');
        Route::patch('contacts/{customer}', [OrganizationContactController::class, 'update'])->withoutScopedBindings()->name('contacts.update');
        Route::delete('contacts/{customer}', [OrganizationContactController::class, 'destroy'])->withoutScopedBindings()->name('contacts.destroy');
    });

    Route::resource('price-lists', PriceListController::class)
        ->only(['index', 'store', 'edit', 'update'])
        ->parameters(['price-lists' => 'priceList']);
    Route::post('price-lists/{priceList}/items', [PriceListItemController::class, 'store'])->name('price-lists.items.store');
    Route::delete('price-lists/{priceList}/items/{item}', [PriceListItemController::class, 'destroy'])
        ->scopeBindings()
        ->name('price-lists.items.destroy');

    // Inventory
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::patch('stock-levels/{level}', [StockLevelController::class, 'update'])->name('stock-levels.update');

    Route::get('queue', [FulfillmentController::class, 'queue'])->name('orders.queue');
    Route::post('orders/{order}/pick', [FulfillmentController::class, 'start'])->name('orders.pick');
    Route::patch('shipments/{shipment}', [FulfillmentController::class, 'assign'])->name('shipments.assign');
    Route::get('pick/{shipment}', [FulfillmentController::class, 'pick'])->name('shipments.pick');
    Route::post('shipments/{shipment}/lines/{line}', [FulfillmentController::class, 'record'])->scopeBindings()->name('shipments.lines.record');
    Route::post('shipments/{shipment}/load', [FulfillmentController::class, 'load'])->name('shipments.load');
    Route::post('shipments/{shipment}/cancel', [FulfillmentController::class, 'cancel'])->name('shipments.cancel');
    Route::get('shipments/{shipment}/ticket', [FulfillmentController::class, 'ticket'])->name('shipments.ticket');
    Route::get('deliver/{shipment}', [DeliveryController::class, 'show'])->name('shipments.deliver');
    Route::post('deliver/{shipment}', [DeliveryController::class, 'complete'])->name('shipments.complete');

    Route::get('dispatch', [DispatchController::class, 'board'])->name('dispatch.board');
    Route::post('dispatch/etas', [DispatchController::class, 'etas'])->name('dispatch.etas');
    Route::patch('stops/{stop}', [DispatchController::class, 'schedule'])->name('stops.schedule');
    Route::post('stops/{stop}/en-route', [DispatchController::class, 'enRoute'])->name('stops.en-route');
    Route::post('stops/{stop}/done', [DispatchController::class, 'done'])->name('stops.done');
    Route::post('inventory/adjustments', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.store');
    Route::resource('locations', LocationController::class)->only(['index', 'store', 'update']);

    // Local development only (the controller 404s otherwise).
    Route::get('dev/fake-checkout/{session}', FakeCheckoutController::class)->name('dev.fake-checkout');
});

Route::get('q/{token}', [QuoteController::class, 'show'])->name('quotes.public');
Route::post('q/{token}/accept', [QuoteController::class, 'accept'])->middleware('throttle:10,1')->name('quotes.accept');

Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
Route::post('webhooks/shopify', ShopifyWebhookController::class)->name('webhooks.shopify');

require __DIR__.'/settings.php';
require __DIR__.'/rentals.php';
