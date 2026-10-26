<?php

namespace Database\Seeders;

use App\Enums\PaymentTender;
use App\Enums\PaymentTerms;
use App\Enums\ProductKind;
use App\Enums\RentalPeriod;
use App\Enums\Settlement;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Inventory\InventoryLedger;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Orders\AddOrderLine;
use App\Orders\ConfirmOrder;
use App\Orders\CreateOrder;
use App\Orders\FulfillOrder;
use App\Orders\ReturnRental;
use App\Payments\RecordPayment;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
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

    private function checkIn(Order $order, CarbonImmutable $at): Order
    {
        app(ReturnRental::class)->handle($order, $this->yardLead());

        $order->forceFill(['returned_at' => $at, 'updated_at' => $at])->saveQuietly();
        $this->backdateMovements($order, $at, [StockMovementType::RentalReturn]);

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

        // sku, name, daily rate, deposit (cents)
        $rentals = [
            ['RNT-PLATE', 'Plate compactor', 9500, 25000],
            ['RNT-SAW14', 'Masonry saw, 14 in', 8500, 30000],
            ['RNT-MIXER', 'Mortar mixer', 7500, 20000],
            ['RNT-BUGGY', 'Power buggy', 16500, 50000],
        ];

        foreach ($rentals as [$sku, $name, $rate, $deposit]) {
            $this->products[$sku] = Product::query()->create([
                'sku' => $sku,
                'name' => $name,
                'category' => 'Equipment rental',
                'kind' => ProductKind::Rental,
                'unit' => Unit::Each,
                'rental_period' => RentalPeriod::Day,
                'price_cents' => $rate,
                'deposit_cents' => $deposit,
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
            'RNT-PLATE' => [3, 0, null],
            'RNT-SAW14' => [2, 0, null],
            'RNT-MIXER' => [2, 0, null],
            'RNT-BUGGY' => [1, 0, null],
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

    private function rentals(): void
    {
        $rental = fn (array $header, int $startDaysAgo, int $days) => [
            'type' => 'rental',
            'rental_starts_at' => $this->at($startDaysAgo, '07:30'),
            'rental_ends_at' => $this->at($startDaysAgo - $days, '07:30'),
            ...$header,
        ];

        // Out now, due back tomorrow.
        $order = $this->draft($rental([
            'organization_id' => $this->orgs['brantwood']->id,
            'customer_id' => $this->people['mike']->id,
        ], 1, 2), ['RNT-PLATE' => 1], $this->at(2, '16:00'));
        $this->confirm($order, Settlement::Prepaid, $this->at(2, '16:05'));
        $this->pay($order, PaymentTender::CardTerminal, $this->at(1, '07:25'), reference: 'Auth 549907');
        $this->release($order, $this->at(1, '07:35'));

        // Returned last week.
        $order = $this->draft($rental(['customer_id' => $this->people['dana']->id], 8, 1), ['RNT-SAW14' => 1], $this->at(9, '12:00'));
        $this->confirm($order, Settlement::Prepaid, $this->at(8, '07:20'));
        $this->pay($order, PaymentTender::CardTerminal, $this->at(8, '07:21'), reference: 'Auth 530118');
        $this->release($order, $this->at(8, '07:30'));
        $this->checkIn($order, $this->at(7, '07:10'));

        // Paid, going out tomorrow morning.
        $order = $this->draft($rental([
            'organization_id' => $this->orgs['halton']->id,
            'customer_id' => $this->people['priya']->id,
        ], -1, 3), ['RNT-MIXER' => 1, 'RNT-BUGGY' => 1], $this->at(0, '09:40'));
        $this->confirm($order, Settlement::Prepaid, $this->at(0, '09:45'));
        $this->pay($order, PaymentTender::CardTerminal, $this->at(0, '09:46'), reference: 'Auth 553310');

        // A quote for next week, still in draft.
        $this->draft($rental([
            'organization_id' => $this->orgs['greenline']->id,
            'customer_id' => $this->people['ali']->id,
        ], -6, 5), ['RNT-PLATE' => 2], $this->at(0, '11:20'));
    }
}
