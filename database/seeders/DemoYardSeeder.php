<?php

namespace Database\Seeders;

use App\Enums\FuelLevel;
use App\Enums\MaintenanceKind;
use App\Enums\PaymentTender;
use App\Enums\PaymentTerms;
use App\Enums\ProductKind;
use App\Enums\RentalPeriod;
use App\Enums\RentalTender;
use App\Enums\Settlement;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Inventory\InventoryLedger;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalUnit;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Orders\AddOrderLine;
use App\Orders\ConfirmOrder;
use App\Orders\CreateOrder;
use App\Orders\FulfillOrder;
use App\Payments\RecordPayment;
use App\Rentals\CheckInRental;
use App\Rentals\CheckOutRental;
use App\Rentals\InspectionData;
use App\Rentals\Maintenance;
use App\Rentals\Payments\RentalPayments;
use App\Rentals\ReserveRental;
use App\Rentals\SaveRental;
use App\Rentals\SignAgreement;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Escarpment Stone & Landscape Supply: a fictional two-yard masonry and
 * landscape supply business used for demos, including in production.
 *
 * Idempotent: skipped if the demo yard already exists, so it's safe to run on
 * every deploy. `php artisan yardline:demo:reset` rebuilds it. Dates are
 * relative to the day it runs, so "Today" always has work in every state.
 *
 * Demo accounts have random passwords and can only be entered through /demo.
 */
class DemoYardSeeder extends Seeder
{
    public const SLUG = 'escarpment-stone';

    /**
     * Shown, in this order, on the /demo sign-in page.
     */
    public const ACCOUNTS = [
        [
            'email' => 'gord.hessels@example.com',
            'name' => 'Gord Hessels',
            'role' => UserRole::Owner,
            'title' => 'Owner',
            'description' => 'Sees everything. Sets contractor pricing and credit limits, and can override a credit limit.',
        ],
        [
            'email' => 'lisa.marchetti@example.com',
            'name' => 'Lisa Marchetti',
            'role' => UserRole::Admin,
            'title' => 'Office manager',
            'description' => 'Runs accounts, price lists and payments. Can override a credit limit.',
        ],
        [
            'email' => 'ryan.tran@example.com',
            'name' => 'Ryan Tran',
            'role' => UserRole::Staff,
            'title' => 'Counter sales',
            'description' => 'Takes orders and payments at the counter. Can’t change prices or override credit.',
        ],
        [
            'email' => 'owen.bakker@example.com',
            'name' => 'Owen Bakker',
            'role' => UserRole::Staff,
            'title' => 'Yard lead',
            'description' => 'Receives and counts stock, releases loads and checks rentals back in.',
        ],
    ];

    private const TZ = 'America/Toronto';

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Product> */
    private array $products = [];

    /** @var array<string, Organization> */
    private array $orgs = [];

    /** @var array<string, Customer> */
    private array $people = [];

    private Location $waterdown;

    private Location $stoneyCreek;

    private int $contractorListId;

    private int $builderListId;

    public function run(): void
    {
        if (Tenant::query()->where('slug', self::SLUG)->exists()) {
            $this->command?->info('Escarpment Stone demo yard already exists, skipping.');

            return;
        }

        DB::transaction(function () {
            $tenant = Tenant::query()->create([
                'name' => 'Escarpment Stone & Landscape Supply',
                'slug' => self::SLUG,
                'currency' => 'CAD',
                'timezone' => self::TZ,
            ]);

            CurrentTenant::run($tenant, function () use ($tenant) {
                $this->users($tenant);
                $this->yards();
                $this->catalog();
                $this->pricing();
                $this->accounts();
                $this->openingStock();
                $this->orders();
                $this->rentals();
            });
        });

        $this->command?->info('Escarpment Stone demo yard created.');
    }

    // ------------------------------------------------------------- helpers

    /**
     * A time N days ago (negative for the future), at a local time of day.
     */
    private function at(int $daysAgo, string $time = '09:00'): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)
            ->subDays($daysAgo)
            ->setTimeFromTimeString($time)
            ->utc();
    }

    private function owner(): User
    {
        return $this->users['gord.hessels@example.com'];
    }

    private function counter(): User
    {
        return $this->users['ryan.tran@example.com'];
    }

    private function yardLead(): User
    {
        return $this->users['owen.bakker@example.com'];
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, string|int|float>  $lines  sku => quantity
     */
    private function draft(array $header, array $lines, CarbonImmutable $createdAt, ?User $by = null): Order
    {
        $order = app(CreateOrder::class)->handle([
            'location_id' => $this->waterdown->id,
            'fulfillment' => 'pickup',
            ...$header,
        ], $by ?? $this->counter());

        foreach ($lines as $sku => $quantity) {
            app(AddOrderLine::class)->handle($order, $this->products[$sku], (string) $quantity);
        }

        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $order->refresh();
    }

    private function confirm(Order $order, Settlement $settlement, CarbonImmutable $at): Order
    {
        app(ConfirmOrder::class)->handle($order, $settlement, $this->owner());

        $order->forceFill(['confirmed_at' => $at, 'updated_at' => $at])->saveQuietly();

        return $order->refresh();
    }

    private function pay(Order $order, PaymentTender $tender, CarbonImmutable $at, ?int $amountCents = null, ?string $reference = null): Order
    {
        $payment = app(RecordPayment::class)->handle(
            $order,
            $tender,
            $amountCents ?? $order->refresh()->balanceCents(),
            $this->counter(),
            $reference,
        );

        $payment->forceFill(['succeeded_at' => $at, 'created_at' => $at, 'updated_at' => $at])->saveQuietly();

        return $order->refresh();
    }

    private function release(Order $order, CarbonImmutable $at): Order
    {
        app(FulfillOrder::class)->handle($order, $this->yardLead());

        $order->forceFill(['fulfilled_at' => $at, 'updated_at' => $at])->saveQuietly();
        $this->backdateMovements($order, $at, [StockMovementType::Sale, StockMovementType::RentalOut]);

        return $order->refresh();
    }

    /**
     * @param  list<StockMovementType>  $types
     */
    private function backdateMovements(Order $order, CarbonImmutable $at, array $types): void
    {
        StockMovement::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->id)
            ->whereIn('type', array_map(fn (StockMovementType $type) => $type->value, $types))
            ->update(['created_at' => $at]);
    }

    // --------------------------------------------------------------- setup

    private function users(Tenant $tenant): void
    {
        foreach (self::ACCOUNTS as $account) {
            $user = User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $account['name'],
                'email' => $account['email'],
                'role' => $account['role'],
                // Never used: demo accounts can only be entered through /demo.
                'password' => Hash::make(Str::random(40)),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->users[$account['email']] = $user;
        }
    }

    private function yards(): void
    {
        $this->waterdown = Location::query()->create([
            'name' => 'Waterdown yard',
            'code' => 'WTD',
            'address_line1' => '100 Dundas St E',
            'city' => 'Waterdown',
            'region' => 'ON',
            'postal_code' => 'L9H 7L6',
            'country' => 'CA',
            'is_default' => true,
        ]);

        $this->stoneyCreek = Location::query()->create([
            'name' => 'Stoney Creek yard',
            'code' => 'STC',
            'address_line1' => '415 Barton St',
            'city' => 'Stoney Creek',
            'region' => 'ON',
            'postal_code' => 'L8E 2L3',
            'country' => 'CA',
        ]);
    }

    private function catalog(): void
    {
        // sku, name, category, unit, list price (cents)
        $sale = [
            ['AGG-SCR', 'Limestone screenings', 'Aggregates', Unit::Tonne, 4200],
            ['AGG-34C', '3/4" clear stone', 'Aggregates', Unit::Tonne, 4800],
            ['AGG-RVR', 'River rock, 1–2"', 'Aggregates', Unit::Tonne, 11500],
            ['SOIL-3MX', 'Triple mix soil', 'Soil & mulch', Unit::CubicYard, 5500],
            ['SOIL-GDN', 'Garden blend soil', 'Soil & mulch', Unit::CubicYard, 6400],
            ['MUL-BCD', 'Black cedar mulch', 'Soil & mulch', Unit::CubicYard, 6200],
            ['PAV-60CH', 'Paver, 60 mm, charcoal', 'Pavers', Unit::SquareFoot, 545],
            ['PAV-80NT', 'Paver, 80 mm, natural', 'Pavers', Unit::SquareFoot, 625],
            ['FLG-ARM', 'Armour flagstone, pallet', 'Natural stone', Unit::Pallet, 68900],
            ['ARM-23', 'Armour stone, 2–3 ft', 'Natural stone', Unit::Tonne, 18500],
            ['WAL-STD', 'Retaining wall block, standard', 'Walls', Unit::Each, 1295],
            ['WAL-CAP', 'Retaining wall cap', 'Walls', Unit::Each, 1850],
            ['BRK-MOD', 'Clay brick, modular, red range', 'Brick & block', Unit::Cube, 129500],
            ['BLK-8IN', 'Concrete block, 8 in', 'Brick & block', Unit::Each, 349],
            ['MOR-S30', 'Type S mortar, 30 kg', 'Mortar & cement', Unit::Bag, 1695],
            ['CEM-GU30', 'Portland cement GU, 30 kg', 'Mortar & cement', Unit::Bag, 1495],
            ['SND-POLY', 'Polymeric sand, 25 kg', 'Mortar & cement', Unit::Bag, 3995],
            ['FAB-GEO', 'Geotextile fabric, 6 ft', 'Accessories', Unit::LinearFoot, 175],
        ];

        foreach ($sale as [$sku, $name, $category, $unit, $price]) {
            $this->products[$sku] = Product::query()->create([
                'sku' => $sku,
                'name' => $name,
                'category' => $category,
                'unit' => $unit,
                'price_cents' => $price,
                'cost_cents' => (int) round($price * 0.62),
                'kind' => ProductKind::Sale,
            ]);
        }

        // sku, name, use, day, half day, weekend, week, month, deposit, fuel, cleaning, service hours
        $rentals = [
            ['RNT-PLATE', 'Plate compactor', 'Compacting base for patios and walkways', 9500, 6500, 14000, 38000, 105000, 25000, 2500, 4000, 100],
            ['RNT-SAW14', 'Masonry saw, 14 in', 'Cutting pavers, block and wall caps', 8500, 5500, 12500, 34000, 95000, 30000, 2500, 5000, 150],
            ['RNT-MIXER', 'Mortar mixer', 'Mixing mortar and grout on site', 7500, 5000, 11000, 30000, 82500, 20000, 2000, 6000, 200],
            ['RNT-BUGGY', 'Power buggy', 'Moving stone, soil and mix across a site', 16500, 11000, 24500, 49500, 135000, 50000, 3500, 5000, 150],
            ['RNT-EXC17', 'Mini excavator, 1.7 t', 'Digging footings, trenches and patio bases', 32900, 22500, 49500, 129900, 349900, 100000, 9500, 7500, 250],
            ['RNT-TILL', 'Rear-tine tiller', 'Breaking up and amending garden beds', 6500, 4500, 9500, 26000, 72000, 15000, 1500, 3000, 100],
        ];

        foreach ($rentals as [$sku, $name, $use, $day, $half, $weekend, $week, $month, $deposit, $fuel, $cleaning, $service]) {
            $this->products[$sku] = Product::query()->create([
                'sku' => $sku,
                'name' => $name,
                'use_case' => $use,
                'category' => str_contains($name, 'excavator') || str_contains($name, 'buggy') ? 'Earthmoving' : 'Hardscape tools',
                'kind' => ProductKind::Rental,
                'unit' => Unit::Each,
                'rental_period' => RentalPeriod::Day,
                'price_cents' => $day,
                'rate_half_day_cents' => $half,
                'rate_weekend_cents' => $weekend,
                'rate_week_cents' => $week,
                'rate_month_cents' => $month,
                'minimum_charge_cents' => $half,
                'deposit_cents' => $deposit,
                'fuel_charge_cents' => $fuel,
                'cleaning_fee_cents' => $cleaning,
                'service_interval_hours' => $service,
                'track_inventory' => false,
                'bookable_online' => true,
            ]);
        }
    }

    private function pricing(): void
    {
        $contractor = PriceList::query()->create([
            'name' => 'Contractor',
            'description' => 'Trade pricing for landscapers and masons on account.',
        ]);

        $prices = [
            'PAV-60CH' => [1 => 490, 1000 => 465],
            'PAV-80NT' => [1 => 565],
            'WAL-STD' => [1 => 1165, 200 => 1095],
            'WAL-CAP' => [1 => 1695],
            'MOR-S30' => [1 => 1525, 50 => 1450],
            'SND-POLY' => [1 => 3695],
            'SOIL-3MX' => [1 => 4950],
            'AGG-SCR' => [1 => 3900, 20 => 3500],
            'AGG-34C' => [1 => 4400, 20 => 4100],
        ];

        foreach ($prices as $sku => $tiers) {
            foreach ($tiers as $minQuantity => $cents) {
                $contractor->items()->create([
                    'product_id' => $this->products[$sku]->id,
                    'min_quantity' => $minQuantity,
                    'price_cents' => $cents,
                ]);
            }
        }

        $builder = PriceList::query()->create([
            'name' => 'Builder volume',
            'description' => 'Home builders buying by the truckload.',
        ]);

        foreach (['BLK-8IN' => 315, 'BRK-MOD' => 118500, 'CEM-GU30' => 1350, 'AGG-34C' => 3950] as $sku => $cents) {
            $builder->items()->create(['product_id' => $this->products[$sku]->id, 'min_quantity' => 1, 'price_cents' => $cents]);
        }

        $this->contractorListId = $contractor->id;
        $this->builderListId = $builder->id;
    }

    private function accounts(): void
    {
        $contractorList = $this->contractorListId;
        $builderList = $this->builderListId;

        $this->orgs['brantwood'] = Organization::query()->create([
            'name' => 'Brantwood Landscaping',
            'account_number' => 'C-10021',
            'email' => 'office@brantwood.example',
            'phone' => '905-555-0142',
            'address_line1' => '22 Mill St N',
            'city' => 'Waterdown',
            'region' => 'ON',
            'postal_code' => 'L8B 1A1',
            'price_list_id' => $contractorList,
            'credit_limit_cents' => 1_500_000,
            'payment_terms' => PaymentTerms::Net30,
        ]);

        $this->orgs['halton'] = Organization::query()->create([
            'name' => 'Halton Masonry Ltd.',
            'account_number' => 'C-10034',
            'email' => 'ap@haltonmasonry.example',
            'phone' => '905-555-0177',
            'address_line1' => '1180 Plains Rd E',
            'city' => 'Burlington',
            'region' => 'ON',
            'postal_code' => 'L7S 1W6',
            'price_list_id' => $contractorList,
            'credit_limit_cents' => 600_000,
            'payment_terms' => PaymentTerms::Net15,
        ]);

        $this->orgs['kerncliff'] = Organization::query()->create([
            'name' => 'Kerncliff Homes',
            'account_number' => 'C-10057',
            'email' => 'purchasing@kerncliff.example',
            'price_list_id' => $builderList,
            'credit_limit_cents' => 2_500_000,
            'payment_terms' => PaymentTerms::Net30,
        ]);

        $this->orgs['lakeshore'] = Organization::query()->create([
            'name' => 'Lakeshore Pools & Patios',
            'account_number' => 'C-10063',
            'email' => 'hello@lakeshorepools.example',
            'phone' => '289-555-0191',
            'notes' => 'New account. Prepaid until they’ve been with us six months.',
        ]);

        $this->orgs['greenline'] = Organization::query()->create([
            'name' => 'Greenline Property Care',
            'account_number' => 'C-10070',
            'email' => 'ops@greenline.example',
            'price_list_id' => $contractorList,
            'credit_limit_cents' => 800_000,
            'payment_terms' => PaymentTerms::Net30,
        ]);

        $people = [
            'mike' => ['brantwood', 'Mike', 'Brant', '905-555-0143', 'mike@brantwood.example'],
            'jen' => ['brantwood', 'Jen', 'Okafor', '905-555-0148', 'jen@brantwood.example'],
            'priya' => ['halton', 'Priya', 'Singh', '905-555-0178', 'priya@haltonmasonry.example'],
            'tom' => ['kerncliff', 'Tom', 'Delaney', '905-555-0110', 'tom@kerncliff.example'],
            'sam' => ['lakeshore', 'Sam', 'Ferreira', '289-555-0192', 'sam@lakeshorepools.example'],
            'ali' => ['greenline', 'Ali', 'Haddad', '905-555-0133', null],
            'dana' => [null, 'Dana', 'Kowalski', '289-555-0110', 'dana.k@example.com'],
            'marcus' => [null, 'Marcus', 'Leblanc', '905-555-0164', null],
            'helen' => [null, 'Helen', 'Wu', '289-555-0125', 'helen.wu@example.com'],
        ];

        foreach ($people as $key => [$org, $first, $last, $phone, $email]) {
            $this->people[$key] = Customer::query()->create([
                'organization_id' => $org ? $this->orgs[$org]->id : null,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'email' => $email,
            ]);
        }
    }

    private function openingStock(): void
    {
        $ledger = app(InventoryLedger::class);

        // sku => [Waterdown, Stoney Creek, reorder point at Waterdown]
        $stock = [
            'AGG-SCR' => [160, 90, 40],
            'AGG-34C' => [110, 60, 30],
            'AGG-RVR' => [28, 0, 10],
            'SOIL-3MX' => [75, 40, 20],
            'SOIL-GDN' => [30, 0, 10],
            'MUL-BCD' => [52, 30, 15],
            'PAV-60CH' => [5200, 0, 1500],
            'PAV-80NT' => [2400, 0, 800],
            'FLG-ARM' => [14, 0, 4],
            'ARM-23' => [18, 0, 15],
            'WAL-STD' => [900, 0, 300],
            'WAL-CAP' => [260, 0, 80],
            'BRK-MOD' => [22, 0, 6],
            'BLK-8IN' => [1600, 400, 500],
            'MOR-S30' => [140, 40, 80],
            'CEM-GU30' => [190, 60, 60],
            'SND-POLY' => [70, 20, 40],
            'FAB-GEO' => [1200, 0, 300],
        ];

        foreach ($stock as $sku => [$waterdown, $stoneyCreek, $reorder]) {
            $product = $this->products[$sku];
            $note = $product->isRental() ? 'Rental fleet' : 'Opening stock';

            $ledger->record($product, $this->waterdown, $waterdown, StockMovementType::Receipt, user: $this->yardLead(), note: $note);

            if ($stoneyCreek > 0) {
                $ledger->record($product, $this->stoneyCreek, $stoneyCreek, StockMovementType::Receipt, user: $this->yardLead(), note: $note);
            }

            if ($reorder !== null) {
                StockLevel::query()
                    ->where('product_id', $product->id)
                    ->where('location_id', $this->waterdown->id)
                    ->update(['reorder_point' => $reorder]);
            }
        }

        StockMovement::query()->update(['created_at' => $this->at(45, '07:30')]);

        // A delivery that came in last week, and a count that found breakage.
        $receipt = $ledger->record($this->products['PAV-60CH'], $this->waterdown, 1800, StockMovementType::Receipt, user: $this->yardLead(), note: 'PO 4471, Unilock');
        $receipt->forceFill(['created_at' => $this->at(5, '08:15')])->saveQuietly();

        $count = $ledger->count($this->products['WAL-CAP'], $this->waterdown, 252, $this->yardLead(), 'Monthly count, 8 chipped');
        $count?->forceFill(['created_at' => $this->at(3, '16:40')])->saveQuietly();
    }

    // -------------------------------------------------------------- orders

    private function orders(): void
    {
        $brantwood = $this->orgs['brantwood'];
        $halton = $this->orgs['halton'];
        $kerncliff = $this->orgs['kerncliff'];
        $lakeshore = $this->orgs['lakeshore'];
        $greenline = $this->orgs['greenline'];

        $delivery = fn (string $line1, string $city, string $postal) => [
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => $line1, 'city' => $city, 'region' => 'ON', 'postal_code' => $postal, 'country' => 'CA'],
        ];

        // Kerncliff: a large account order 40 days ago, still unpaid. That's
        // why they're on credit hold now.
        $order = $this->draft([
            'organization_id' => $kerncliff->id,
            'customer_id' => $this->people['tom']->id,
            ...$delivery('Lot 14, Kerncliff Estates', 'Burlington', 'L7P 0A1'),
            'po_number' => 'KH-2207',
        ], ['BLK-8IN' => 900, 'BRK-MOD' => 6, 'CEM-GU30' => 60], $this->at(41, '10:20'), $this->owner());
        $this->confirm($order, Settlement::Account, $this->at(41, '10:30'));
        $this->release($order, $this->at(40, '07:10'));
        $kerncliff->update(['credit_hold' => true, 'notes' => 'On hold until the KH-2207 invoice is paid. Tom says a cheque is coming.']);

        // Brantwood: paid in full by cheque.
        $order = $this->draft([
            'organization_id' => $brantwood->id,
            'customer_id' => $this->people['mike']->id,
            ...$delivery('9 Orchard Cres', 'Waterdown', 'L8B 0C2'),
            'po_number' => 'BW-ORCHARD',
        ], ['PAV-80NT' => 520, 'AGG-SCR' => 14, 'SND-POLY' => 6], $this->at(24, '08:05'));
        $this->confirm($order, Settlement::Account, $this->at(24, '08:10'));
        $this->release($order, $this->at(23, '07:00'));
        $this->pay($order, PaymentTender::Cheque, $this->at(9, '11:00'), reference: 'Cheque 3381');

        // Brantwood: released last week, unpaid on terms.
        $order = $this->draft([
            'organization_id' => $brantwood->id,
            'customer_id' => $this->people['jen']->id,
            ...$delivery('251 Hamilton St N', 'Waterdown', 'L8B 1B4'),
            'po_number' => 'BW-HAMILTONST',
        ], ['PAV-60CH' => 450, 'SND-POLY' => 6, 'AGG-SCR' => 18, 'FAB-GEO' => 120], $this->at(7, '14:30'));
        $this->confirm($order, Settlement::Account, $this->at(7, '14:35'));
        $this->release($order, $this->at(6, '07:20'));

        // Halton: two open account orders that use most of their $6,000 limit.
        $order = $this->draft([
            'organization_id' => $halton->id,
            'customer_id' => $this->people['priya']->id,
            'po_number' => 'HM-ALDERSHOT',
        ], ['BRK-MOD' => 2, 'MOR-S30' => 40], $this->at(9, '07:40'));
        $this->confirm($order, Settlement::Account, $this->at(9, '07:45'));
        $this->release($order, $this->at(9, '08:30'));

        $order = $this->draft([
            'organization_id' => $halton->id,
            'customer_id' => $this->people['priya']->id,
            ...$delivery('47 Townsend Ave', 'Burlington', 'L7T 1Y8'),
            'requested_on' => $this->at(-1)->setTimezone(self::TZ)->toDateString(),
            'po_number' => 'HM-TOWNSEND',
        ], ['BLK-8IN' => 300, 'MOR-S30' => 10], $this->at(0, '07:50'));
        $this->confirm($order, Settlement::Account, $this->at(0, '08:00'));

        // Halton: a new draft that would put them over their limit. Confirming
        // shows the credit check and the owner override.
        $this->draft([
            'organization_id' => $halton->id,
            'customer_id' => $this->people['priya']->id,
            'po_number' => 'HM-SPENCER-CREEK',
        ], ['BRK-MOD' => 1, 'MOR-S30' => 20], $this->at(0, '09:15'));

        // Brantwood: today's delivery, in draft, priced from the contractor list
        // with the screenings volume break.
        $this->draft([
            'organization_id' => $brantwood->id,
            'customer_id' => $this->people['mike']->id,
            ...$delivery('48 Parkside Dr', 'Waterdown', 'L8B 0A9'),
            'requested_on' => $this->at(-2)->setTimezone(self::TZ)->toDateString(),
            'po_number' => 'BW-PARKSIDE',
            'notes' => 'Back gate, side yard. Call Mike 30 min out.',
        ], ['PAV-60CH' => 640, 'SND-POLY' => 8, 'AGG-SCR' => 22, 'WAL-STD' => 48, 'WAL-CAP' => 16], $this->at(0, '08:40'));

        // Greenline: confirmed on account, ready to load.
        $order = $this->draft([
            'organization_id' => $greenline->id,
            'customer_id' => $this->people['ali']->id,
            'po_number' => 'GL-0193',
        ], ['MUL-BCD' => 12, 'SOIL-3MX' => 6], $this->at(0, '07:05'));
        $this->confirm($order, Settlement::Account, $this->at(0, '07:06'));

        // Lakeshore: prepaid account. One paid and gone, one waiting on payment.
        $order = $this->draft([
            'organization_id' => $lakeshore->id,
            'customer_id' => $this->people['sam']->id,
            ...$delivery('12 Lakeview Ct', 'Grimsby', 'L3M 4E7'),
            'po_number' => 'LP-MORRIS',
        ], ['FLG-ARM' => 3, 'AGG-34C' => 8, 'FAB-GEO' => 200], $this->at(12, '13:10'));
        $this->confirm($order, Settlement::Prepaid, $this->at(12, '13:15'));
        $this->pay($order, PaymentTender::CardTerminal, $this->at(12, '13:16'), reference: 'Auth 448120');
        $this->release($order, $this->at(11, '07:30'));

        $order = $this->draft([
            'organization_id' => $lakeshore->id,
            'customer_id' => $this->people['sam']->id,
            'po_number' => 'LP-CHEN',
        ], ['PAV-80NT' => 380, 'SND-POLY' => 5, 'AGG-SCR' => 9], $this->at(0, '10:05'));
        $this->confirm($order, Settlement::Prepaid, $this->at(0, '10:10'));

        // Walk-ins: one paid and picked up today, one waiting at the counter,
        // one from earlier in the week.
        $order = $this->draft(['customer_id' => $this->people['dana']->id], ['MUL-BCD' => 3, 'SOIL-GDN' => 2], $this->at(0, '08:20'));
        $this->confirm($order, Settlement::Prepaid, $this->at(0, '08:21'));
        $this->pay($order, PaymentTender::CardTerminal, $this->at(0, '08:22'), reference: 'Auth 551902');
        $this->release($order, $this->at(0, '08:35'));

        $order = $this->draft(['customer_id' => $this->people['marcus']->id], ['CEM-GU30' => 6, 'AGG-34C' => 1], $this->at(0, '10:40'));
        $this->confirm($order, Settlement::Prepaid, $this->at(0, '10:41'));

        $order = $this->draft(['customer_id' => $this->people['helen']->id], ['ARM-23' => 4, 'AGG-RVR' => 2], $this->at(4, '15:00'));
        $this->confirm($order, Settlement::Prepaid, $this->at(4, '15:02'));
        $this->pay($order, PaymentTender::ETransfer, $this->at(4, '15:30'), reference: 'Ref CA8812Q');
        $this->release($order, $this->at(3, '07:45'));

        // Mortar and sand drawn down by the week's counter sales: they show
        // under "Running low" on Today.
        $ledger = app(InventoryLedger::class);
        foreach (['MOR-S30' => -38, 'SND-POLY' => -24] as $sku => $quantity) {
            $movement = $ledger->record($this->products[$sku], $this->waterdown, $quantity, StockMovementType::Adjustment, user: $this->yardLead(), note: 'Counter sales this week');
            $movement->forceFill(['created_at' => $this->at(1, '17:00')])->saveQuietly();
        }
    }

    // ------------------------------------------------------------- rentals

    /** A hand-drawn signature for the demo agreements. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAPAAAABGCAQAAABYz9FxAAACl0lEQVR42u2bW3LDIAxFgyfLyP5X5n2kHx6PxzWYlySEc85XmyZG1UVCCPJ6AQAAAAAAAAAAAAAAAAAAwK/x+X6+eMEzoU/e/ac14MqHCbyJuwm7C43M/lj65X291oC0jxL4LO8RvazHjxA4Ji8J+mEpOiUmMTy9wHcSEsP+eEvG7zYBkLklYLS8FurNuTcFgVtzoY7fqgXOmZGfAhArVVOlq+kaXFpCjSi1Pt+6tmnt+3Xl/d8yGhTBZenXOobrU51Ncqz3kUYUh16jRkscT3Wp8a9/1UqNbf6RtyZIx6+l01LjSL2uZ3d6LOngWOqc2b+N0pd3742f19jtt3jXXGv9a/WkpCVqMamfpnMjXN2Ue7fFxMx7UjafLDryeoiDc7SWnHjpx3CJJ82LrFZ5dWNC/uk2Oae8ypexZZkxenXEsDnwLLFasiZY9OSdr581tmaotaSsVbPoRq9ORGgmU80YXoOULYe0OZnfmql5DZwPt06czXfXHXO8VZPeWSv3U3RiTTuCdSxuLVSPz7U0at76q5H0AeKs51UtNu9xfP+MNVRG8G9vZ2qfnz+y782IPZ8f1i/2vVstP1TJR+nYjaaL5py3BF1+LHq2Jf6fjl1SFv0hpFv5dh3j2mkbP+QYu/KbntqmxC6XzO5IIHfz7M5u2wsF97aaH3T3dm/sjiL7iib525Jp77kROC1R2Tptt5rd2aNbNLXJ6Ejg9qiwrUbTo8lMM2kh3QucF3DMZb54q7Du8tKoUtGZwPnEaL3ZKN0IeRByCoH9XIuLTas5v5XlzrBYpI7rBR0j+7z44KLRIVOAjHHtvm+fVV6HAl9bIWN7QcdlvTm/ceXU6PlTIwJXpGnkfaDAY4srAAAAAAAAAAAAABDkDz4VeEPQ4wq3AAAAAElFTkSuQmCC';

    /** @var array<string, RentalUnit> */
    private array $units = [];

    private function rentals(): void
    {
        $this->rentalSetup();
        $this->fleet();

        $tz = self::TZ;
        $day = fn (int $daysFromNow, string $time) => CarbonImmutable::now($tz)->addDays($daysFromNow)->setTimeFromTimeString($time);

        // Brantwood has the excavator on a monthly rate. Card on file.
        $this->people['mike']->forceFill(['stripe_payment_method_id' => 'pm_demo_visa', 'card_brand' => 'visa', 'card_last4' => '4242'])->save();
        $rental = $this->quote([
            'organization_id' => $this->orgs['brantwood']->id,
            'customer_id' => $this->people['mike']->id,
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '48 Flamboro St', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L8B 1H7', 'country' => 'CA'],
            'payment_method' => 'card_on_file',
            'text_reminders' => true,
        ], $day(-10, '07:00'), $day(18, '17:00'), ['EX-01']);
        $this->goOut($rental, $day(-10, '07:10'), ['EX-01' => 1212.0]);

        // A plate compactor due back today.
        $rental = $this->quote([
            'organization_id' => $this->orgs['halton']->id,
            'customer_id' => $this->people['priya']->id,
            'payment_method' => 'counter',
        ], $day(-2, '07:30'), $day(0, '17:00'), ['PC-01']);
        $this->goOut($rental, $day(-2, '07:40'), ['PC-01' => 61.5], RentalTender::CardTerminal);

        // A walk-in who hasn't brought the saw back.
        $rental = $this->quote([
            'customer_id' => $this->people['marcus']->id,
            'payment_method' => 'counter',
            'text_reminders' => true,
        ], $day(-3, '08:00'), $day(-1, '17:00'), ['SAW-01']);
        $this->goOut($rental, $day(-3, '08:05'), ['SAW-01' => 410.0], RentalTender::Cash);

        // Back last week: a weekend on the free-Sunday rule, returned with a dent.
        $saturday = CarbonImmutable::now($tz)->previous(CarbonImmutable::SATURDAY)->subWeek()->setTimeFromTimeString('08:00');
        $rental = $this->quote([
            'customer_id' => $this->people['dana']->id,
            'payment_method' => 'counter',
            'damage_waiver' => true,
        ], $saturday, $saturday->addDays(2)->setTimeFromTimeString('08:00'), ['PB-01']);
        $this->goOut($rental, $saturday->addMinutes(10), ['PB-01' => 388.0], RentalTender::CardTerminal);
        $item = $rental->items()->firstOrFail();
        app(CheckInRental::class)->handle($rental, [
            new InspectionData($item->id, 392.5, FuelLevel::Low, true, 'Dent on the left side of the tub.', damageDescription: 'Dent, left side of tub', damageCents: 18000, damageCoveredByWaiver: true),
        ], $this->yardLead(), $saturday->addDays(2)->setTimeFromTimeString('07:50'));
        $this->backdate($rental, $saturday->addDays(2)->setTimeFromTimeString('07:50'));

        // Reserved for tomorrow: paid, agreement still to sign.
        $rental = $this->quote([
            'organization_id' => $this->orgs['kerncliff']->id,
            'customer_id' => $this->people['tom']->id,
            'payment_method' => 'counter',
        ], $day(1, '07:00'), $day(3, '17:00'), ['MX-01', 'SAW-02']);
        app(ReserveRental::class)->handle($rental);
        app(RentalPayments::class)->recordCounterPayment($rental, RentalTender::ETransfer, $rental->total_cents, $this->counter(), 'e-Transfer CA8812');

        // Booked online for the weekend, signed and paid.
        $nextSaturday = CarbonImmutable::now($tz)->next(CarbonImmutable::SATURDAY)->setTimeFromTimeString('08:00');
        $this->people['helen']->forceFill(['stripe_payment_method_id' => 'pm_demo_mc', 'card_brand' => 'mastercard', 'card_last4' => '4444'])->save();
        $rental = $this->quote([
            'customer_id' => $this->people['helen']->id,
            'payment_method' => 'card_on_file',
            'source' => 'online',
        ], $nextSaturday, $nextSaturday->addDays(2), ['PC-02']);
        app(ReserveRental::class)->handle($rental);
        $this->sign($rental, 'Helen Wu', 'online');
        app(RentalPayments::class)->chargeCardOnFile($rental, null);

        // A quote for a patio job next week, delivered.
        $this->quote([
            'organization_id' => $this->orgs['greenline']->id,
            'customer_id' => $this->people['ali']->id,
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '2100 Lakeshore Rd', 'city' => 'Burlington', 'region' => 'ON', 'postal_code' => 'L7R 1A3', 'country' => 'CA'],
            'payment_method' => 'payment_link',
        ], $day(6, '07:00'), $day(13, '17:00'), ['PC-03', 'MX-02']);

        $this->maintenanceHistory();
    }

    private function rentalSetup(): void
    {
        foreach ([
            ['Zone 1 · Waterdown and Flamborough', ['L8B', 'L9H', 'L0R'], 6500],
            ['Zone 2 · Hamilton and Stoney Creek', ['L8', 'L9'], 8500],
            ['Zone 3 · Burlington and Oakville', ['L7', 'L6'], 11500],
        ] as $position => [$name, $prefixes, $fee]) {
            DeliveryZone::query()->create(['name' => $name, 'postal_prefixes' => $prefixes, 'fee_cents' => $fee, 'position' => $position + 1]);
        }
    }

    private function fleet(): void
    {
        // serial, sku, hour meter, last service at
        $fleet = [
            ['PC-01', 'RNT-PLATE', 61.5, 0.0],
            ['PC-02', 'RNT-PLATE', 140.0, 100.0],
            ['PC-03', 'RNT-PLATE', 22.0, 0.0],
            ['SAW-01', 'RNT-SAW14', 410.0, 300.0],
            ['SAW-02', 'RNT-SAW14', 96.0, 0.0],
            ['MX-01', 'RNT-MIXER', 520.0, 400.0],
            ['MX-02', 'RNT-MIXER', 188.0, 0.0],
            ['PB-01', 'RNT-BUGGY', 388.0, 300.0],
            ['EX-01', 'RNT-EXC17', 1212.0, 1000.0],
            ['TL-01', 'RNT-TILL', 97.0, 0.0],
        ];

        foreach ($fleet as [$serial, $sku, $hours, $lastService]) {
            $this->units[$serial] = RentalUnit::query()->create([
                'product_id' => $this->products[$sku]->id,
                'location_id' => $this->waterdown->id,
                'serial' => $serial,
                'hour_meter' => $hours,
                'last_service_hours' => $lastService,
                'last_service_on' => $this->at(60)->toDateString(),
                'purchased_on' => $this->at(400)->toDateString(),
                'purchase_cost_cents' => (int) round($this->products[$sku]->price_cents * 38, -2),
            ]);
        }
    }

    private function maintenanceHistory(): void
    {
        $maintenance = app(Maintenance::class);

        $record = $maintenance->logService($this->units['PB-01'], '150-hr service: oil, filters, track tension', 300.0, 18500, $this->yardLead());
        $record->forceFill(['opened_at' => $this->at(40, '13:00'), 'completed_at' => $this->at(40, '15:30')])->save();

        $record = $maintenance->logService($this->units['MX-01'], 'Drum seals and grease', 400.0, 6400, $this->yardLead());
        $record->forceFill(['opened_at' => $this->at(25, '10:00'), 'completed_at' => $this->at(25, '11:15')])->save();

        $record = $maintenance->open($this->units['TL-01'], MaintenanceKind::Repair, 'Tine shaft bearing grinding. Parts ordered.', $this->yardLead());
        $record->forceFill(['opened_at' => $this->at(2, '16:20')])->save();
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<string>  $serials
     */
    private function quote(array $header, CarbonImmutable $start, CarbonImmutable $end, array $serials): Rental
    {
        $items = array_map(fn (string $serial) => [
            'product_id' => $this->units[$serial]->product_id,
            'rental_unit_id' => $this->units[$serial]->id,
        ], $serials);

        $rental = app(SaveRental::class)->handle([
            'location_id' => $this->waterdown->id,
            'fulfillment' => 'pickup',
            'damage_waiver' => true,
            'bill_fuel_cleaning' => true,
            ...$header,
            'starts_at' => $start->utc(),
            'ends_at' => $end->utc(),
        ], $items, $this->counter());

        $rental->forceFill(['created_at' => $start->subDays(2)->min(CarbonImmutable::now())])->save();

        return $rental;
    }

    private function sign(Rental $rental, string $name, string $via): void
    {
        app(SignAgreement::class)->handle($rental, $name, self::SIGNATURE, true, $via, Request::create('/', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']));
    }

    /**
     * Reserve, sign at the counter, take payment and send it out, then move
     * the timestamps back to when it really went.
     *
     * @param  array<string, float>  $hours  hour meter by serial
     */
    private function goOut(Rental $rental, CarbonImmutable $at, array $hours, ?RentalTender $tender = null): void
    {
        app(ReserveRental::class)->handle($rental);
        $customer = $rental->customer;
        $this->sign($rental, $customer !== null ? $customer->full_name : 'Walk-in', 'counter');

        if ($tender !== null) {
            app(RentalPayments::class)->recordCounterPayment($rental->refresh(), $tender, $rental->total_cents, $this->counter(), $tender === RentalTender::CardTerminal ? 'Auth '.random_int(500000, 599999) : null);
        }

        $items = $rental->items()->with('unit')->get();
        $inspections = $items->map(fn ($item) => new InspectionData(
            $item->id,
            $hours[$item->unit?->serial] ?? null,
            FuelLevel::Full,
            true,
            null,
        ))->all();

        app(CheckOutRental::class)->handle($rental->refresh(), $inspections, $this->yardLead(), $tender);
        $rental->forceFill(['checked_out_at' => $at])->save();
        $this->backdate($rental, $at);
    }

    private function backdate(Rental $rental, CarbonImmutable $at): void
    {
        $rental->payments()->where('succeeded_at', '>', $at)->update(['succeeded_at' => $at, 'created_at' => $at]);
        DB::table('rental_agreements')->where('rental_id', $rental->id)->update(['signed_at' => $at->subMinutes(5)]);
        DB::table('rental_inspections')->where('rental_id', $rental->id)->where('created_at', '>', $at)->update(['created_at' => $at]);
        DB::table('rental_notifications')->where('rental_id', $rental->id)->update(['sent_at' => $at->subMinutes(10)]);
    }
}
