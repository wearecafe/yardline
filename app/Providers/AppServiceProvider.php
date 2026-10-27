<?php

namespace App\Providers;

use App\Models\ContractPrice;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\CycleCount;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PayPeriod;
use App\Models\PriceList;
use App\Models\PriceListCategoryRule;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Rental;
use App\Models\RentalPayment;
use App\Models\RentalUnit;
use App\Models\Shipment;
use App\Models\StockTransfer;
use App\Models\Tenant;
use App\Models\TimeAdjustment;
use App\Models\Timesheet;
use App\Models\User;
use App\Payments\CheckoutLinks;
use App\Payments\FakeCheckoutLinks;
use App\Payments\Fees\CardFees;
use App\Payments\Fees\EstimatedCardFees;
use App\Payments\Fees\StripeCardFees;
use App\Payments\StripeCheckoutLinks;
use App\Rentals\Payments\FakeRentalGateway;
use App\Rentals\Payments\RentalGateway;
use App\Rentals\Payments\StripeRentalGateway;
use App\Rentals\RentalSettings;
use App\Reports\ReportSettings;
use App\Support\Demo;
use App\Support\StripeGateway;
use App\Tax\FlatRateTaxCalculator;
use App\Tax\StripeTaxCalculator;
use App\Tax\TaxCalculator;
use App\Time\TimeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StripeGateway::class, fn () => new StripeGateway(config('services.stripe.secret')));

        // The demo yard never talks to Stripe: flat tax and fake payment links,
        // whatever the configured drivers are.
        $this->app->bind(TaxCalculator::class, fn (Application $app) => match (Demo::isCurrentTenant() ? 'flat' : config('yardline.tax.driver')) {
            'stripe' => $app->make(StripeTaxCalculator::class),
            'flat' => new FlatRateTaxCalculator((float) config('yardline.tax.flat_rate')),
            default => throw new RuntimeException('Unknown YARDLINE_TAX_DRIVER: '.config('yardline.tax.driver')),
        });

        $this->app->bind(RentalGateway::class, function (Application $app) {
            if (Demo::isCurrentTenant()) {
                return new FakeRentalGateway;
            }

            $driver = config('yardline.payments.driver');

            if ($driver === 'fake' && $app->isProduction()) {
                throw new RuntimeException('The fake payments driver cannot be used in production.');
            }

            return $driver === 'stripe' ? $app->make(StripeRentalGateway::class) : new FakeRentalGateway;
        });

        // Card fees for the profit report: read from Stripe, or estimated
        // where there's no processor (local development, the demo yard).
        $this->app->bind(CardFees::class, fn (Application $app) => ! Demo::isCurrentTenant() && config('yardline.payments.driver') === 'stripe'
            ? $app->make(StripeCardFees::class)
            : new EstimatedCardFees);

        $this->app->bind(RentalSettings::class, fn () => RentalSettings::current());
        $this->app->bind(TimeSettings::class, fn () => TimeSettings::current());

        $this->app->bind(CheckoutLinks::class, function (Application $app) {
            if (Demo::isCurrentTenant()) {
                return new FakeCheckoutLinks;
            }

            $driver = config('yardline.payments.driver');

            if ($driver === 'fake' && $app->isProduction()) {
                throw new RuntimeException('The fake payments driver cannot be used in production.');
            }

            return match ($driver) {
                'stripe' => $app->make(StripeCheckoutLinks::class),
                'fake' => new FakeCheckoutLinks,
                default => throw new RuntimeException("Unknown YARDLINE_PAYMENTS_DRIVER: {$driver}"),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureModels();
        $this->configureGates();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureModels(): void
    {
        // Stable names in polymorphic columns (stock movement references,
        // external references) instead of PHP class names.
        Relation::enforceMorphMap([
            'contract_price' => ContractPrice::class,
            'credit_note' => CreditNote::class,
            'customer' => Customer::class,
            'employee' => Employee::class,
            'invoice' => Invoice::class,
            'location' => Location::class,
            'order' => Order::class,
            'order_line' => OrderLine::class,
            'organization' => Organization::class,
            'payment' => Payment::class,
            'pay_period' => PayPeriod::class,
            'price_list' => PriceList::class,
            'price_list_category_rule' => PriceListCategoryRule::class,
            'price_list_item' => PriceListItem::class,
            'product' => Product::class,
            'product_unit' => ProductUnit::class,
            'purchase_order' => PurchaseOrder::class,
            'stock_transfer' => StockTransfer::class,
            'tenant' => Tenant::class,
            'cycle_count' => CycleCount::class,
            'rental' => Rental::class,
            'rental_payment' => RentalPayment::class,
            'rental_unit' => RentalUnit::class,
            'shipment' => Shipment::class,
            'time_adjustment' => TimeAdjustment::class,
            'timesheet' => Timesheet::class,
            'user' => User::class,
        ]);
    }

    protected function configureGates(): void
    {
        Gate::define('manage-business', fn (User $user) => $user->canManageBusiness());
        Gate::define('manage-users', fn (User $user) => $user->canManageUsers());
        Gate::define('override-credit', fn (User $user) => $user->canManageBusiness());
        // Who's in, timesheet review and approval, corrections, payroll export.
        Gate::define('manage-time', fn (User $user) => $user->canManageBusiness());
        // Costs, margins and the profit report: the owner, and admins if the owner allows.
        Gate::define('view-profit', fn (User $user) => ReportSettings::for($user->tenant)->canViewProfit($user));
    }
}
