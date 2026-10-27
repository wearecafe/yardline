<?php

use App\Billing\Plan;
use App\Http\Controllers\BrandLogoController;
use App\Http\Controllers\ContractPriceController;
use App\Http\Controllers\CounterController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CycleCountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\FakeCheckoutController;
use App\Http\Controllers\FulfillmentController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\OrderActionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderLineController;
use App\Http\Controllers\OrganizationContactController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationLocationController;
use App\Http\Controllers\PickerSwitchController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\PriceListCategoryRuleController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\PriceListItemController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductUnitController;
use App\Http\Controllers\ProfitReportController;
use App\Http\Controllers\PurchasingController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ReceivablesController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockLevelController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\Webhooks\StripeBillingWebhookController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Support\Demo;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', fn () => Inertia::render('welcome', [
    'demo' => Demo::enabled(),
    'canRegister' => Features::enabled(Features::registration()),
    'pricing' => Plan::catalog(),
]))->name('home');

// Where the installed app opens: each person's home screen for their roles.
Route::get('start', [PwaController::class, 'start'])->name('pwa.start');

// The public help center. Articles are Markdown in resources/help.
Route::controller(HelpController::class)->prefix('help')->name('help.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('search', 'search')->name('search');
    Route::get('suggest', 'suggest')->middleware('throttle:120,1')->name('suggest');
    Route::get('{category}', 'category')->where('category', '[a-z0-9-]+')->name('category');
    Route::get('{category}/{article}', 'article')->where(['category' => '[a-z0-9-]+', 'article' => '[a-z0-9-]+'])->name('article');
});

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
    Route::get('products/{product}/fit', [ProductUnitController::class, 'fit'])->name('products.fit');

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
    Route::post('price-lists/{priceList}/category-rules', [PriceListCategoryRuleController::class, 'store'])->name('price-lists.category-rules.store');
    Route::delete('price-lists/{priceList}/category-rules/{rule}', [PriceListCategoryRuleController::class, 'destroy'])
        ->scopeBindings()
        ->name('price-lists.category-rules.destroy');

    // Inventory
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::patch('stock-levels/{level}', [StockLevelController::class, 'update'])->name('stock-levels.update');
    Route::post('stock-transfers', [StockTransferController::class, 'store'])->name('stock-transfers.store');
    Route::post('cycle-counts', [CycleCountController::class, 'store'])->name('cycle-counts.store');
    Route::get('cycle-counts/{cycleCount}', [CycleCountController::class, 'show'])->name('cycle-counts.show');
    Route::put('cycle-counts/{cycleCount}', [CycleCountController::class, 'update'])->name('cycle-counts.update');
    Route::post('cycle-counts/{cycleCount}/complete', [CycleCountController::class, 'complete'])->name('cycle-counts.complete');

    Route::get('purchasing', [PurchasingController::class, 'index'])->name('purchasing.index');
    Route::post('purchasing', [PurchasingController::class, 'store'])->name('purchasing.store');
    Route::post('suppliers', [PurchasingController::class, 'storeSupplier'])->name('suppliers.store');
    Route::get('purchasing/{purchaseOrder}', [PurchasingController::class, 'show'])->name('purchasing.show');
    Route::put('purchasing/{purchaseOrder}', [PurchasingController::class, 'update'])->name('purchasing.update');
    Route::post('purchasing/{purchaseOrder}/send', [PurchasingController::class, 'send'])->name('purchasing.send');
    Route::post('purchasing/{purchaseOrder}/receive', [PurchasingController::class, 'receive'])->name('purchasing.receive');
    Route::post('purchasing/{purchaseOrder}/cancel', [PurchasingController::class, 'cancel'])->name('purchasing.cancel');

    Route::get('queue', [FulfillmentController::class, 'queue'])->name('orders.queue');
    Route::post('orders/{order}/pick', [FulfillmentController::class, 'start'])->name('orders.pick');
    Route::patch('shipments/{shipment}', [FulfillmentController::class, 'assign'])->name('shipments.assign');
    Route::get('pick/{shipment}', [FulfillmentController::class, 'pick'])->name('shipments.pick');
    Route::post('shipments/{shipment}/lines/{line}', [FulfillmentController::class, 'record'])->scopeBindings()->name('shipments.lines.record');
    Route::post('shipments/{shipment}/picked', [FulfillmentController::class, 'picked'])->name('shipments.picked');
    Route::post('shipments/{shipment}/load', [FulfillmentController::class, 'load'])->name('shipments.load');
    Route::post('shipments/{shipment}/sign-off', [FulfillmentController::class, 'signOff'])->name('shipments.sign-off');
    Route::post('shipments/{shipment}/reject', [FulfillmentController::class, 'reject'])->name('shipments.reject');
    // A shared yard tablet: an order picker switches in with their PIN.
    Route::post('picker/switch', PickerSwitchController::class)->middleware('throttle:10,1')->name('picker.switch');
    Route::post('shipments/{shipment}/cancel', [FulfillmentController::class, 'cancel'])->name('shipments.cancel');
    Route::get('shipments/{shipment}/ticket', [FulfillmentController::class, 'ticket'])->name('shipments.ticket');
    Route::get('deliver/{shipment}', [DeliveryController::class, 'show'])->name('shipments.deliver');
    Route::post('deliver/{shipment}', [DeliveryController::class, 'complete'])->name('shipments.complete');

    Route::get('counter', [CounterController::class, 'show'])->name('counter.show');
    Route::get('counter/prices', [CounterController::class, 'prices'])->name('counter.prices');
    Route::post('counter', [CounterController::class, 'store'])->name('counter.store');
    Route::get('counter/receipt/{order}', [CounterController::class, 'receipt'])->name('counter.receipt');

    Route::get('receivables', [ReceivablesController::class, 'index'])->name('receivables.index');
    Route::get('organizations/{organization}/statement', [ReceivablesController::class, 'statement'])->name('organizations.statement');
    Route::get('organizations/{organization}/statement.pdf', [ReceivablesController::class, 'pdf'])->name('organizations.statement.pdf');
    Route::post('organizations/{organization}/statement/email', [ReceivablesController::class, 'email'])->name('organizations.statement.email');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');

    // Profit and loss, down to gross profit: the owner, and admins if allowed.
    Route::controller(ProfitReportController::class)->middleware('can:view-profit')->prefix('reports/profit')->group(function () {
        Route::get('/', 'index')->name('reports.profit');
        Route::get('lines', 'detail')->name('reports.profit.lines');
        Route::get('export.csv', 'csv')->name('reports.profit.csv');
        Route::get('export.pdf', 'pdf')->name('reports.profit.pdf');
    });

    Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('orders/{order}/returns/create', [ReturnController::class, 'create'])->name('orders.returns.create');
    Route::post('orders/{order}/returns', [ReturnController::class, 'store'])->name('orders.returns.store');
    Route::get('credit-notes/{creditNote}', [ReturnController::class, 'show'])->name('credit-notes.show');
    Route::post('credit-notes/{creditNote}/apply', [ReturnController::class, 'apply'])->name('credit-notes.apply');
    Route::post('credit-notes/{creditNote}/refund', [ReturnController::class, 'refund'])->name('credit-notes.refund');
    Route::post('pallet-returns', [ReturnController::class, 'pallets'])->name('pallet-returns.store');

    Route::get('dispatch', [DispatchController::class, 'board'])->name('dispatch.board');
    Route::post('dispatch/etas', [DispatchController::class, 'etas'])->name('dispatch.etas');
    Route::put('dispatch/trucks/{truck}/driver', [DispatchController::class, 'assignDriver'])->name('dispatch.driver');
    Route::patch('stops/{stop}', [DispatchController::class, 'schedule'])->name('stops.schedule');
    Route::post('stops/{stop}/en-route', [DispatchController::class, 'enRoute'])->name('stops.en-route');
    Route::post('stops/{stop}/done', [DispatchController::class, 'done'])->name('stops.done');

    // The driver's phone: their run, each stop, and where they are.
    Route::controller(DriverController::class)->prefix('driver')->name('driver.')->group(function () {
        Route::get('/', 'today')->name('today');
        Route::get('stops/{stop}', 'show')->name('stops.show');
        Route::post('stops/{stop}/start', 'start')->name('stops.start');
        Route::post('stops/{stop}/done', 'done')->name('stops.done');
        Route::post('location', 'locate')->middleware('throttle:30,1')->name('locate');
    });

    Route::post('inventory/adjustments', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.store');
    Route::resource('locations', LocationController::class)->only(['index', 'store', 'update']);

    // Local development only (the controller 404s otherwise).
    Route::get('dev/fake-checkout/{session}', FakeCheckoutController::class)->name('dev.fake-checkout');
});

// The contractor portal.
Route::middleware('portal')->prefix('portal/account')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'home'])->name('home');
    Route::get('orders', [PortalController::class, 'orders'])->name('orders');
    Route::get('orders/{order}', [PortalController::class, 'order'])->name('order');
    Route::post('orders/{order}/reorder', [PortalController::class, 'reorder'])->name('reorder');
    Route::post('calculator', [PortalController::class, 'calculate'])->name('calculate');
    Route::get('calculator/pavers', [PortalController::class, 'pavers'])->name('calculator.pavers');
    Route::get('statement', [PortalController::class, 'statement'])->name('statement');
    Route::get('statement.pdf', [PortalController::class, 'statementPdf'])->name('statement.pdf');
    Route::post('pallet-pickup', [PortalController::class, 'palletPickup'])->name('pallet-pickup');
    Route::post('logout', [PortalAuthController::class, 'logout'])->name('logout');
});

// A yard's logo for its emails: public, since email apps don't sign in.
Route::get('brand/{tenant:slug}/logo', BrandLogoController::class)->name('brand.logo');

Route::get('portal/{tenant:slug}', [PortalAuthController::class, 'show'])->name('portal.login');
Route::post('portal/{tenant:slug}', [PortalAuthController::class, 'send'])->middleware('throttle:6,1')->name('portal.send');
Route::get('portal/{tenant:slug}/enter/{customer}', [PortalAuthController::class, 'enter'])->middleware('signed')->whereNumber('customer')->name('portal.enter');
Route::post('portal/{tenant:slug}/demo', [PortalAuthController::class, 'demo'])->middleware('throttle:20,1')->name('portal.demo');

Route::get('q/{token}', [QuoteController::class, 'show'])->name('quotes.public');
Route::post('q/{token}/accept', [QuoteController::class, 'accept'])->middleware('throttle:10,1')->name('quotes.accept');

Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
// Tenants' YardRunner subscriptions (YardRunner's own Stripe account).
Route::post('webhooks/stripe/billing', StripeBillingWebhookController::class)->name('webhooks.stripe-billing');

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/rentals.php';
require __DIR__.'/time.php';
