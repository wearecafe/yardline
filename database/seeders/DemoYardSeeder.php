<?php

namespace Database\Seeders;

use App\Audit\Audit;
use App\Enums\FuelLevel;
use App\Enums\MaintenanceKind;
use App\Enums\PaymentTender;
use App\Enums\PaymentTerms;
use App\Enums\ProductKind;
use App\Enums\QuantityRounding;
use App\Enums\RentalPeriod;
use App\Enums\RentalTender;
use App\Enums\Settlement;
use App\Enums\StockMovementType;
use App\Enums\StopStatus;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Fulfillment\CompleteShipment;
use App\Fulfillment\Dispatch;
use App\Fulfillment\DriverRun;
use App\Fulfillment\LoadShipment;
use App\Fulfillment\RecordPick;
use App\Fulfillment\StartPicking;
use App\Inventory\InventoryLedger;
use App\Models\AuditEvent;
use App\Models\ContractPrice;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLocation;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Rental;
use App\Models\RentalUnit;
use App\Models\Sequence;
use App\Models\StockLevel;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Stop;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Truck;
use App\Models\User;
use App\Orders\AddOrderLine;
use App\Orders\ConfirmOrder;
use App\Orders\CreateOrder;
use App\Orders\FulfillOrder;
use App\Orders\SendQuote;
use App\Payments\RecordPayment;
use App\Purchasing\CycleCounting;
use App\Purchasing\ReceivePurchaseOrder;
use App\Purchasing\TransferStock;
use App\Receivables\PalletLedger;
use App\Receivables\ProcessReturn;
use App\Receivables\UseCredit;
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
use Illuminate\Database\Eloquent\Model;
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
        [
            'email' => 'sam.rinaldi@example.com',
            'name' => 'Sam Rinaldi',
            'role' => UserRole::Driver,
            'title' => 'Driver',
            'description' => 'Drives Truck 2, the boom truck. Sees only his run on his phone: stops in order, directions, proof of delivery. Shares his location with dispatch.',
        ],
    ];

    /**
     * Truck 2's way from the Waterdown yard to Brantwood's Hamilton St
     * site. yardline:demo:drive moves the truck along it so the board and
     * the portal always have a fresh position.
     *
     * @var list<array{float, float}>
     */
    public const DEMO_ROUTE = [
        [43.33352, -79.87953],
        [43.33371, -79.88262],
        [43.33389, -79.88581],
        [43.33405, -79.88874],
        [43.33431, -79.89148],
        [43.33512, -79.89262],
        [43.33617, -79.89301],
        [43.33702, -79.89322],
    ];

    public const DEMO_DRIVER = 'sam.rinaldi@example.com';

    private const TZ = 'America/Toronto';

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Product> */
    private array $products = [];

    /** @var array<string, Organization> */
    private array $orgs = [];

    /** @var array<string, Customer> */
    private array $people = [];

    /** @var list<Truck> */
    private array $trucks = [];

    /** @var array<string, Supplier> */
    private array $suppliers = [];

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
                $this->sellingUnits();
                $this->accounts();
                $this->accountDetails();
                $this->purchasingSetup();
                $this->openingStock();
                $this->openingLots();
                $this->deliverySetup($tenant);
                $this->orders();
                $this->quotes();
                $this->fulfillmentToday();
                $this->returnsAndCredits();
                $this->purchasingActivity();
                $this->rentals();
                $this->portalActivity();
                $this->auditHistory();
                $this->pavers80ByLayer();
            });
        });

        $this->command?->info('Escarpment Stone demo yard created.');
    }

    // ------------------------------------------------------- portal + audit

    /**
     * The 80 mm pavers sell by the layer or the pallet, never a single
     * paver: square feet only count stock, and an area that doesn't come
     * to whole layers rounds up to the next one. Set last, so the older
     * demo orders that sold them by the square foot could be built.
     */
    private function pavers80ByLayer(): void
    {
        $pavers = $this->products['PAV-80NT'];

        ProductUnit::query()->where('product_id', $pavers->id)->where('is_base', true)->update(['sellable' => false]);
        Product::query()->whereKey($pavers->id)->update(['quantity_rounding' => QuantityRounding::Up->value]);
    }

    /**
     * What contractors have done in the portal: a reorder waiting for the
     * counter to confirm and a pallet pickup to schedule.
     */
    private function portalActivity(): void
    {
        $mike = $this->people['mike'];
        $last = Order::query()->where('organization_id', $this->orgs['brantwood']->id)->where('status', '!=', 'draft')->latest('id')->first();

        Audit::$customerId = $mike->id;

        try {
            $draft = $this->draft([
                'type' => 'sale',
                'organization_id' => $this->orgs['brantwood']->id,
                'customer_id' => $mike->id,
                'fulfillment' => 'delivery',
                'delivery_address' => ['line1' => '48 Flamboro St', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L8B 0E2', 'country' => 'CA'],
            ], $last !== null
                ? $last->lines()->with('product')->get()->filter(fn ($l) => $l->product !== null && $l->product->kind->value === 'sale')->mapWithKeys(fn ($l) => [$l->product->sku => (string) ($l->base_quantity ?? $l->quantity)])->all()
                : ['PAV-60CH' => 120],
                $this->at(0, '07:12'));
            $draft->forceFill([
                'source' => 'portal',
                'notes' => ($last ? "Reorder of {$last->number}" : 'Reorder').'. Requested in the portal by Mike Brant.',
            ])->saveQuietly();

            $halton = $this->orgs['halton'];
            Stop::query()->create([
                'stoppable_type' => $halton->getMorphClass(),
                'stoppable_id' => $halton->id,
                'kind' => 'pallet_pickup',
                'status' => StopStatus::Unscheduled,
                'date' => $this->at(-1)->setTimezone(self::TZ)->toDateString(),
                'address' => ['line1' => $halton->address_line1, 'city' => $halton->city, 'postal_code' => $halton->postal_code],
                'needs' => 'Pick up 12 pallets · asked by Priya Singh',
                'duration_minutes' => 30,
            ]);
        } finally {
            Audit::$customerId = null;
        }
    }

    /**
     * Seeding writes an audit event for every row it creates, all stamped
     * "now". Swap those for the history a real yard would have: who
     * confirmed each order and when, price and credit changes, and what
     * contractors did in the portal.
     */
    private function auditHistory(): void
    {
        $portal = AuditEvent::query()->whereNotNull('customer_id')->pluck('id');
        AuditEvent::query()->whereNotIn('id', $portal)->delete();
        AuditEvent::query()->whereIn('id', $portal)->update(['created_at' => $this->at(0, '07:12')]);

        $log = function (Model $subject, string $event, CarbonImmutable $at, ?User $by, ?array $changes = null, ?string $note = null): void {
            AuditEvent::query()->create([
                'user_id' => $by?->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => (int) $subject->getKey(),
                'subject_label' => $subject->getAttribute('number') ?? $subject->getAttribute('name'),
                'event' => $event,
                'changes' => $changes,
                'note' => $note,
                'created_at' => $at,
            ]);
        };

        foreach (Order::query()->whereNotNull('confirmed_at')->where('source', '!=', 'portal')->get() as $order) {
            $by = $order->created_by !== null ? User::query()->find($order->created_by) : $this->counter();
            $log($order, 'created', CarbonImmutable::parse($order->created_at), $by);
            $log($order, 'updated', CarbonImmutable::parse($order->confirmed_at), $by, ['status' => ['draft', 'confirmed']]);
        }

        foreach (['PAV-60CH' => [52, 515], 'WAL-CAP' => [18, 1_750]] as $sku => [$daysAgo, $was]) {
            $product = $this->products[$sku];
            $log($product, 'updated', $this->at($daysAgo, '16:40'), $this->owner(), ['price_cents' => [$was, $product->price_cents]], 'Spring price increase from the supplier');
        }

        $halton = $this->orgs['halton'];
        $log($halton, 'updated', $this->at(21, '11:05'), $this->owner(), ['credit_limit_cents' => [400_000, $halton->credit_limit_cents]], 'Two seasons paying on time');
        $log($this->orgs['lakeshore'], 'updated', $this->at(9, '14:30'), $this->owner(), ['payment_terms' => ['net_30', 'due_on_receipt']], 'New account, prepaid for six months');
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

        // "SKU" sells in the base unit; "SKU@Unit name" in another selling unit.
        foreach ($lines as $key => $quantity) {
            [$sku, $unitName] = array_pad(explode('@', $key, 2), 2, null);
            $product = $this->products[$sku];
            $unit = $unitName !== null ? $product->units()->where('name', $unitName)->firstOrFail() : null;
            app(AddOrderLine::class)->handle($order, $product, (string) $quantity, null, $unit);
        }

        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $order->refresh();
    }

    private function confirm(Order $order, Settlement $settlement, CarbonImmutable $at, ?string $override = null): Order
    {
        app(ConfirmOrder::class)->handle($order, $settlement, $this->owner(), $override);

        $order->forceFill(['confirmed_at' => $at, 'updated_at' => $at])->saveQuietly();

        // The invoice was issued the day it was confirmed.
        $invoice = $order->invoice()->first();
        $invoice?->forceFill([
            'issued_on' => $at->toDateString(),
            'due_on' => $at->addDays($invoice->terms->days())->toDateString(),
            'created_at' => $at,
        ])->saveQuietly();

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

    /**
     * Picked, loaded and delivered (or handed over) in the past, with proof
     * of delivery and the truck run done.
     */
    private function release(Order $order, CarbonImmutable $at): Order
    {
        app(FulfillOrder::class)->handle($order, $this->yardLead());
        $shipment = $order->shipments()->latest('id')->firstOrFail();
        $delivered = $at->addMinutes(95);

        if ($shipment->isDelivery()) {
            $stop = $shipment->stop()->firstOrFail();
            $stop->forceFill(['truck_id' => $this->trucks[0]->id, 'date' => $at->toDateString(), 'starts_at' => $at->setTimezone(self::TZ)->addHour()->format('H:00'), 'status' => 'scheduled'])->save();
            app(CompleteShipment::class)->handle($shipment->refresh(), $order->customer->full_name ?? 'Site foreman', self::SIGNATURE, null, null);
            $stop->forceFill(['completed_at' => $delivered])->save();
        }

        $shipment->forceFill(['created_at' => $at->subMinutes(30), 'picked_at' => $at->subMinutes(10), 'loaded_at' => $at, 'delivered_at' => $shipment->isDelivery() ? $delivered : $at, 'updated_at' => $at])->saveQuietly();
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
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('reference_type', $order->getMorphClass())->where('reference_id', $order->id))
                ->orWhere(fn ($q) => $q->where('reference_type', 'shipment')->whereIn('reference_id', $order->shipments()->pluck('id'))))
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

    /**
     * The ways each material is sold, with prices per unit, and what it
     * weighs and who supplies it.
     */
    private function sellingUnits(): void
    {
        // sku => [weight kg per base unit, supplier]
        $details = [
            'AGG-SCR' => [1000, 'Niagara Ridge Quarry'],
            'AGG-34C' => [1000, 'Niagara Ridge Quarry'],
            'AGG-RVR' => [1000, 'Grand River Aggregates'],
            'SOIL-3MX' => [1100, 'Flamborough Soils'],
            'SOIL-GDN' => [1050, 'Flamborough Soils'],
            'MUL-BCD' => [300, 'Flamborough Soils'],
            'PAV-60CH' => [12.5, 'Ridgeline Concrete Products'],
            'PAV-80NT' => [16.5, 'Ridgeline Concrete Products'],
            'FLG-ARM' => [1400, 'Escarpment Stone Co.'],
            'ARM-23' => [1000, 'Escarpment Stone Co.'],
            'WAL-STD' => [32, 'Ridgeline Concrete Products'],
            'WAL-CAP' => [22, 'Ridgeline Concrete Products'],
            'BRK-MOD' => [2100, 'Lakeview Brick'],
            'BLK-8IN' => [17, 'Ridgeline Concrete Products'],
            'MOR-S30' => [30, 'Hamilton Building Supply'],
            'CEM-GU30' => [30, 'Hamilton Building Supply'],
            'SND-POLY' => [25, 'Hamilton Building Supply'],
        ];

        foreach ($details as $sku => [$kg, $supplier]) {
            $this->products[$sku]->forceFill(['weight_kg' => $kg, 'supplier_name' => $supplier])->save();
        }

        // Pavers, wall block and brick ship on deposit pallets.
        foreach (['PAV-60CH' => 115, 'PAV-80NT' => 90, 'WAL-STD' => 48, 'WAL-CAP' => 60, 'BRK-MOD' => null, 'FLG-ARM' => null] as $sku => $perPallet) {
            $this->products[$sku]->forceFill(['pallet_deposit' => true, 'per_pallet' => $perPallet])->save();
        }

        // sku => [[name, factor, retail, contractor, measured by, increment, deposit]]
        $units = [
            'AGG-SCR' => [
                ['Cubic yard', 1.35, 5600, 5100, 'bucket', 0.5, 0],
                ['Half yard', 0.675, 2900, 2650, 'bucket', 1, 0],
                ['Bulk bag', 1, 7900, 7200, 'count', 1, 2500],
                ['25 kg bag', 0.025, 649, 549, 'count', 1, 0],
            ],
            'AGG-34C' => [
                ['Cubic yard', 1.4, 6500, 5900, 'bucket', 0.5, 0],
                ['Bulk bag', 1, 8500, 7700, 'count', 1, 2500],
            ],
            'SOIL-3MX' => [
                ['Half yard', 0.5, 2950, 2650, 'bucket', 1, 0],
                ['Bulk bag', 1, 6900, 6300, 'count', 1, 2500],
            ],
            'MUL-BCD' => [
                ['Half yard', 0.5, 3300, null, 'bucket', 1, 0],
                ['Bag, 2 cu ft', 0.074, 799, null, 'count', 1, 0],
            ],
            'PAV-60CH' => [['Pallet (115 sq ft)', 115, 59900, 54900, 'count', 1, 0]],
            'PAV-80NT' => [
                ['Pallet (90 sq ft)', 90, 52900, 48500, 'count', 1, 0],
                ['Layer (9 sq ft)', 9, 5490, 4990, 'count', 1, 0],
            ],
            'WAL-STD' => [['Pallet (48 blocks)', 48, 59900, 53900, 'count', 1, 0]],
        ];

        $contractor = PriceList::query()->findOrFail($this->contractorListId);

        foreach ($units as $sku => $rows) {
            $product = $this->products[$sku];
            $product->units()->where('is_base', true)->update(['measured_by' => in_array($sku, ['AGG-SCR', 'AGG-34C'], true) ? 'scale' : 'count']);

            foreach ($rows as $position => [$name, $factor, $retail, $trade, $measure, $increment, $deposit]) {
                $unit = $product->units()->create([
                    'name' => $name,
                    'factor' => $factor,
                    'price_cents' => $retail,
                    'variant_title' => $name,
                    'measured_by' => $measure,
                    'increment' => $increment,
                    'deposit_cents' => $deposit,
                    'position' => $position + 1,
                ]);

                if ($trade !== null) {
                    $contractor->items()->create(['product_id' => $product->id, 'product_unit_id' => $unit->id, 'min_quantity' => 1, 'price_cents' => $trade]);
                }
            }
        }

        // The quarry put screenings up 4% last week.
        $this->products['AGG-SCR']->forceFill([
            'cost_cents' => 3140,
            'previous_cost_cents' => 3020,
            'cost_changed_at' => $this->at(6, '09:00'),
        ])->save();

        // A spring promotion on the contractor list, coming up.
        $contractor->items()->create([
            'product_id' => $this->products['PAV-60CH']->id,
            'min_quantity' => 1,
            'price_cents' => 449,
            'starts_on' => CarbonImmutable::today(self::TZ)->addDays(20)->toDateString(),
            'ends_on' => CarbonImmutable::today(self::TZ)->addDays(50)->toDateString(),
        ]);
    }

    /**
     * Job sites, contact roles and contract prices.
     */
    private function accountDetails(): void
    {
        $sites = [
            ['brantwood', 'Orchard Crescent project', '9 Orchard Cres', 'Waterdown', 'L8B 0C2', 'Back gate off the laneway. Text Mike on arrival.', false, true],
            ['brantwood', 'Hamilton St N reno', '251 Hamilton St N', 'Waterdown', 'L8B 1B4', 'Narrow drive: small truck only.', false, false],
            ['kerncliff', 'Kerncliff Estates, phase 2', 'Lot 14, Kerncliff Estates', 'Burlington', 'L7P 0A1', 'Site trailer at the north entrance. Hard hats past the fence.', true, true],
            ['greenline', 'Greenline depot', '780 Plains Rd W', 'Burlington', 'L7T 1H2', 'Drop in bay 3. Open 6:30 to 3.', false, true],
        ];

        foreach ($sites as [$org, $name, $line1, $city, $postal, $notes, $po, $default]) {
            OrganizationLocation::query()->create([
                'organization_id' => $this->orgs[$org]->id,
                'name' => $name,
                'address_line1' => $line1,
                'city' => $city,
                'region' => 'ON',
                'postal_code' => $postal,
                'delivery_notes' => $notes,
                'location_id' => $this->waterdown->id,
                'po_required' => $po,
                'is_default' => $default,
            ]);
        }

        // Roles: who can approve, who can charge to the account.
        $roles = [
            ['mike', 'brantwood', 'admin', true],
            ['jen', 'brantwood', 'approver', true],
            ['tom', 'kerncliff', 'approver', true],
            ['sam', 'lakeshore', 'buyer', false],
        ];

        foreach ($roles as [$person, $org, $role, $charge]) {
            $this->orgs[$org]->contacts()->updateExistingPivot($this->people[$person]->id, ['role' => $role, 'can_charge_account' => $charge]);
        }

        // Jen also orders for Greenline on a subcontract, by card.
        $this->orgs['greenline']->contacts()->attach($this->people['jen']->id, [
            'tenant_id' => $this->orgs['greenline']->tenant_id,
            'role' => 'buyer',
            'can_charge_account' => false,
        ]);

        $contracts = [
            ['brantwood', 'AGG-SCR', null, 3700, null, null, 'Season pricing, agreed in March'],
            ['brantwood', 'AGG-SCR', 'Cubic yard', 4900, null, null, null],
            ['halton', 'MOR-S30', null, 1395, null, CarbonImmutable::today(self::TZ)->endOfYear()->toDateString(), 'Volume deal to year end'],
            ['greenline', 'MUL-BCD', null, 5600, CarbonImmutable::today(self::TZ)->addDays(10)->toDateString(), null, 'Spring mulch contract'],
        ];

        foreach ($contracts as [$org, $sku, $unitName, $cents, $from, $until, $note]) {
            $product = $this->products[$sku];
            ContractPrice::query()->create([
                'organization_id' => $this->orgs[$org]->id,
                'product_id' => $product->id,
                'product_unit_id' => $unitName ? $product->units()->where('name', $unitName)->value('id') : null,
                'price_cents' => $cents,
                'starts_on' => $from,
                'ends_on' => $until,
                'note' => $note,
            ]);
        }
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
            $note = 'Opening stock';

            $ledger->record($product, $this->waterdown, $waterdown, StockMovementType::Receipt, user: $this->yardLead(), note: $note);

            if ($stoneyCreek > 0) {
                $ledger->record($product, $this->stoneyCreek, $stoneyCreek, StockMovementType::Receipt, user: $this->yardLead(), note: $note);
            }

            StockLevel::query()
                ->where('product_id', $product->id)
                ->where('location_id', $this->waterdown->id)
                ->update(['reorder_point' => $reorder]);
        }

        StockMovement::query()->update(['created_at' => $this->at(45, '07:30')]);

        // A delivery that came in last week, and a count that found breakage.
        $receipt = $ledger->record($this->products['PAV-60CH'], $this->waterdown, 1800, StockMovementType::Receipt, user: $this->yardLead(), note: 'PO 4471, Ridgeline Concrete Products', lot: StockLot::query()->firstOrCreate(['product_id' => $this->products['PAV-60CH']->id, 'location_id' => $this->waterdown->id, 'code' => 'Run 2611-B'], ['received_on' => $this->at(5)->toDateString()]));
        $receipt->forceFill(['created_at' => $this->at(5, '08:15')])->saveQuietly();

        $count = $ledger->count($this->products['WAL-CAP'], $this->waterdown, 252, $this->yardLead(), 'Monthly count, 8 chipped');
        $count?->forceFill(['created_at' => $this->at(3, '16:40')])->saveQuietly();
    }

    /**
     * Suppliers, and which products are tracked by colour run.
     */
    private function purchasingSetup(): void
    {
        $leads = ['Niagara Ridge Quarry' => 2, 'Grand River Aggregates' => 3, 'Flamborough Soils' => 2, 'Ridgeline Concrete Products' => 7, 'Escarpment Stone Co.' => 10, 'Lakeview Brick' => 14, 'Hamilton Building Supply' => 3];

        foreach ($leads as $name => $days) {
            $this->suppliers[$name] = Supplier::query()->create([
                'name' => $name,
                'email' => 'orders@'.Str::slug($name).'.example',
                'phone' => '905-555-02'.str_pad((string) count($this->suppliers), 2, '0', STR_PAD_LEFT),
                'lead_days' => $days,
            ]);
        }

        foreach ($this->products as $product) {
            if ($product->supplier_name !== null && isset($this->suppliers[$product->supplier_name])) {
                $product->forceFill(['supplier_id' => $this->suppliers[$product->supplier_name]->id])->save();
            }
        }

        foreach (['PAV-60CH', 'PAV-80NT', 'WAL-STD', 'WAL-CAP'] as $sku) {
            $this->products[$sku]->forceFill(['track_lots' => true])->save();
        }
    }

    /**
     * Opening stock of colour-run products, split into runs. Whatever
     * isn't in a named run is the newest run on the shelf.
     */
    private function openingLots(): void
    {
        $runs = ['PAV-60CH' => ['Run 2609-A' => 690], 'PAV-80NT' => [], 'WAL-STD' => [], 'WAL-CAP' => []];

        foreach ($runs as $sku => $lots) {
            $product = $this->products[$sku];
            $total = (float) app(InventoryLedger::class)->onHand($product, $this->waterdown);

            foreach ($lots as $code => $quantity) {
                StockLot::query()->firstOrCreate(
                    ['product_id' => $product->id, 'location_id' => $this->waterdown->id, 'code' => $code],
                    ['received_on' => $this->at(45)->toDateString()],
                )->forceFill(['on_hand' => $quantity])->save();
            }

            $inRuns = (float) StockLot::query()->where('product_id', $product->id)->where('location_id', $this->waterdown->id)->sum('on_hand');

            if ($inRuns < $total) {
                $newest = StockLot::query()->firstOrCreate(
                    ['product_id' => $product->id, 'location_id' => $this->waterdown->id, 'code' => $sku === 'PAV-60CH' ? 'Run 2611-B' : 'Run 2604-C'],
                    ['received_on' => $this->at(30)->toDateString()],
                );
                $newest->forceFill(['on_hand' => (float) $newest->on_hand + $total - $inRuns])->save();
            }
        }
    }

    /**
     * Purchase orders in each state, a transfer between yards, and counts.
     */
    private function purchasingActivity(): void
    {
        $receive = app(ReceivePurchaseOrder::class);
        $owner = $this->owner();
        $po = function (string $supplier, array $lines, string $status, int $expectedInDays, int $createdDaysAgo) use ($owner): PurchaseOrder {
            $order = PurchaseOrder::query()->create([
                'number' => 'PO-'.Sequence::next('purchase_orders'),
                'supplier_id' => $this->suppliers[$supplier]->id,
                'location_id' => $this->waterdown->id,
                'status' => $status,
                'expected_on' => $this->at(-$expectedInDays)->toDateString(),
                'created_by' => $owner->id,
            ]);

            foreach (array_values($lines) as $i => [$sku, $qty]) {
                $order->lines()->create(['product_id' => $this->products[$sku]->id, 'quantity' => $qty, 'unit_cost_cents' => (int) $this->products[$sku]->cost_cents, 'position' => $i]);
            }

            $order->forceFill([
                'total_cents' => (int) $order->lines()->get()->sum(fn ($l) => (int) round((float) $l->quantity * $l->unit_cost_cents)),
                'sent_at' => $status === 'draft' ? null : $this->at($createdDaysAgo, '10:00'),
                'created_at' => $this->at($createdDaysAgo, '09:30'),
            ])->save();

            return $order;
        };

        // Arriving this week.
        $po('Hamilton Building Supply', [['MOR-S30', 120], ['SND-POLY', 80]], 'sent', 2, 1);
        $po('Lakeview Brick', [['BRK-MOD', 12]], 'sent', 4, 10);

        // Wall block came in short last week: the rest is still to come.
        $order = $po('Ridgeline Concrete Products', [['WAL-STD', 480], ['WAL-CAP', 120]], 'sent', -3, 12);
        $lines = $order->lines()->get();
        $receive->handle($order, [
            ['line_id' => $lines[0]->id, 'quantity' => 288, 'lot' => 'Run 2610-A'],
            ['line_id' => $lines[1]->id, 'quantity' => 120, 'lot' => 'Run 2610-A'],
        ], $this->yardLead());
        StockMovement::query()->where('reference_type', 'purchase_order')->where('reference_id', $order->id)->update(['created_at' => $this->at(4, '08:40')]);

        // A draft for screenings, now that the quarry's price went up.
        $po('Niagara Ridge Quarry', [['AGG-SCR', 120]], 'draft', 3, 0);

        // Screenings moved over from Stoney Creek.
        $transfer = app(TransferStock::class)->handle($this->stoneyCreek, $this->waterdown, [['product_id' => $this->products['AGG-SCR']->id, 'quantity' => 20]], $this->yardLead(), 'Topping up Bay 4');
        $transfer->forceFill(['created_at' => $this->at(5, '13:00')])->save();
        StockMovement::query()->where('reference_type', 'stock_transfer')->where('reference_id', $transfer->id)->update(['created_at' => $this->at(5, '13:00')]);

        // Last week's count of the bagged aisles, and one in progress.
        $counting = app(CycleCounting::class);
        $count = $counting->start($this->waterdown, $this->yardLead(), 'Aisle');
        foreach ($count->lines as $line) {
            $line->update(['counted' => max(0, round((float) $line->expected * 0.982))]);
        }
        $counting->complete($count, $this->yardLead());
        $count->forceFill(['created_at' => $this->at(9, '16:00'), 'completed_at' => $this->at(9, '17:10')])->save();

        $counting->start($this->waterdown, $this->yardLead(), 'Row C');
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

    // --------------------------------------------------------- fulfillment

    /**
     * Today's yard: something in every column of the order queue and on
     * the dispatch board.
     */
    private function fulfillmentToday(): void
    {
        $owen = $this->yardLead();
        $start = app(StartPicking::class);
        $pick = app(RecordPick::class);
        $load = app(LoadShipment::class);
        $dispatch = app(Dispatch::class);
        $today = CarbonImmutable::today(self::TZ);

        // Delivered first thing on Truck 1.
        $depot = OrganizationLocation::query()->where('name', 'Greenline depot')->firstOrFail();
        $order = $this->draft([
            'organization_id' => $this->orgs['greenline']->id,
            'customer_id' => $this->people['ali']->id,
            'organization_location_id' => $depot->id,
            'fulfillment' => 'delivery',
            'delivery_address' => $depot->address(),
            'po_number' => 'GL-0190',
        ], ['SOIL-3MX' => 8], $this->at(1, '15:00'));
        $this->confirm($order, Settlement::Account, $this->at(1, '15:05'));
        $this->release($order, $this->at(0, '07:20'));

        // On Truck 2 now, ETA texted.
        $order = $this->draft([
            'organization_id' => $this->orgs['brantwood']->id,
            'customer_id' => $this->people['jen']->id,
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '251 Hamilton St N', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L8B 1B4', 'country' => 'CA'],
            'po_number' => 'BW-HAMILTON-2',
            'needs_boom' => true,
        ], ['PAV-60CH@Pallet (115 sq ft)' => 6, 'AGG-SCR' => 4, 'SND-POLY' => 12], $this->at(1, '16:30'));
        $this->confirm($order, Settlement::Account, $this->at(1, '16:35'));
        $shipment = $start->handle($order->refresh(), $this->owner(), $owen);
        foreach ($shipment->lines()->with('orderLine')->get() as $line) {
            $pick->handle($line, $line->orderLine->sku === 'AGG-SCR' ? '4.12' : $line->quantity);
        }
        $load->handle($shipment->refresh(), $owen);
        $stop = $shipment->stop()->firstOrFail();
        $dispatch->schedule($stop, $this->trucks[1], $today, '09:00', 120);
        $dispatch->enRoute($stop->refresh(), $today->setTimeFromTimeString('10:40'));
        $stop->forceFill(['eta_sent_at' => $this->at(0, '08:55')])->save();

        // Sam's day on Truck 2 (the demo driver): a drop first thing, the
        // Brantwood run above, and another Brantwood load this afternoon,
        // so the portal shows "1 stop before yours".
        $this->samsRun($today);

        // Greenline's pickup is being picked: mulch done, soil to go.
        $greenline = Order::query()->where('po_number', 'GL-0193')->firstOrFail();
        $shipment = $start->handle($greenline, $this->owner(), $owen);
        $mulch = $shipment->lines()->whereHas('orderLine', fn ($q) => $q->where('sku', 'MUL-BCD'))->firstOrFail();
        $pick->handle($mulch, $mulch->quantity);

        // Halton's delivery for tomorrow is planned on Truck 3.
        $halton = Order::query()->where('po_number', 'HM-TOWNSEND')->firstOrFail();
        $dispatch->schedule($halton->stops()->firstOrFail(), $this->trucks[2], $today->addDay(), '08:00', 90);

        // A walk-in's sand, picked and waiting at the counter.
        $ross = Customer::query()->create(['first_name' => 'Jordan', 'last_name' => 'Ross', 'phone' => '905-555-0188']);
        $order = $this->draft(['customer_id' => $ross->id], ['SND-POLY' => 20], $this->at(0, '07:40'));
        $this->confirm($order, Settlement::Prepaid, $this->at(0, '07:42'));
        $this->pay($order, PaymentTender::CardTerminal, $this->at(0, '07:43'), reference: 'Auth 552210');
        $shipment = $start->handle($order->refresh(), $this->counter(), $owen);
        foreach ($shipment->lines as $line) {
            $pick->handle($line, $line->quantity);
        }
        $load->handle($shipment->refresh(), $owen);

        // Something new with a boom-truck delivery today, not yet planned.
        $order = $this->draft([
            'organization_id' => $this->orgs['halton']->id,
            'customer_id' => $this->people['priya']->id,
            ...['fulfillment' => 'delivery', 'delivery_address' => ['line1' => '18 Ridgeview Dr', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L8B 1J2', 'country' => 'CA']],
            'requested_on' => $today->toDateString(),
            'needs_boom' => true,
            'po_number' => 'HM-RIDGEVIEW',
        ], ['WAL-STD@Pallet (48 blocks)' => 3, 'WAL-CAP' => 40, 'AGG-34C' => 6], $this->at(0, '09:20'));
        $this->confirm($order, Settlement::Account, $this->at(0, '09:25'), override: 'Good for it: cheque for HM-TOWNSEND came in today. (Gord)');
    }

    /**
     * The demo driver's other stops today, and where his truck is.
     */
    private function samsRun(CarbonImmutable $today): void
    {
        $truck = $this->trucks[1];
        $dispatch = app(Dispatch::class);

        // Delivered first thing to Kerncliff, signed for on site.
        $order = $this->draft([
            'organization_id' => $this->orgs['kerncliff']->id,
            'customer_id' => $this->people['tom']->id,
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '62 Mill St S', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L8B 1K1', 'country' => 'CA'],
            'po_number' => 'KC-MILL-07',
        ], ['AGG-34C' => 5, 'FAB-GEO' => 2], $this->at(1, '14:10'));
        $this->confirm($order, Settlement::Prepaid, $this->at(1, '14:15'));
        $this->pay($order, PaymentTender::ETransfer, $this->at(1, '14:20'), reference: 'KC e-Transfer');
        $this->release($order, $this->at(0, '06:30'));
        $order->shipments()->firstOrFail()->stop()->firstOrFail()
            ->forceFill(['truck_id' => $truck->id, 'starts_at' => '07:00', 'duration_minutes' => 60])
            ->save();

        // This afternoon: a second Brantwood site, loaded and waiting.
        $order = $this->draft([
            'organization_id' => $this->orgs['brantwood']->id,
            'customer_id' => $this->people['mike']->id,
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '9 Kerr St', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L8B 0E4', 'country' => 'CA'],
            'po_number' => 'BW-KERR-1',
        ], ['SOIL-3MX' => 6, 'MUL-BCD' => 4], $this->at(0, '07:10'));
        $this->confirm($order, Settlement::Prepaid, $this->at(0, '07:12'));
        $this->pay($order, PaymentTender::CardTerminal, $this->at(0, '07:13'), reference: 'Auth 552301');
        $shipment = app(StartPicking::class)->handle($order->refresh(), $this->owner(), $this->yardLead());
        foreach ($shipment->lines as $line) {
            app(RecordPick::class)->handle($line, $line->quantity);
        }
        app(LoadShipment::class)->handle($shipment->refresh(), $this->yardLead());
        $dispatch->schedule($shipment->stop()->firstOrFail(), $truck, $today, '13:00', 60);

        [$lat, $lng] = self::DEMO_ROUTE[4];
        app(DriverRun::class)->locate($this->users[self::DEMO_DRIVER], $lat, $lng, 12, 38.0);
    }

    /**
     * A return with a restocking fee, pallets back, and a credit used.
     */
    private function returnsAndCredits(): void
    {
        $brantwood = $this->orgs['brantwood'];
        $orchard = Order::query()->where('po_number', 'BW-ORCHARD')->firstOrFail();
        $pavers = $orchard->lines()->where('sku', 'PAV-80NT')->firstOrFail();

        $credit = app(ProcessReturn::class)->handle($orchard, [
            ['order_line_id' => $pavers->id, 'quantity' => 60, 'restock' => true],
        ], $this->yardLead(), 'Over-ordered: one pallet came back unopened');
        $credit->forceFill(['issued_on' => $this->at(8)->toDateString(), 'created_at' => $this->at(8, '14:20')])->saveQuietly();

        $pallets = app(PalletLedger::class)->returned($brantwood, null, 5, $this->waterdown, $this->yardLead());
        $pallets->forceFill(['issued_on' => $this->at(2)->toDateString(), 'created_at' => $this->at(2, '15:40')])->saveQuietly();

        // The pallet credit went against last week's unpaid order.
        $unpaid = Order::query()->where('organization_id', $brantwood->id)->where('payment_status', 'unpaid')->where('status', 'fulfilled')->latest('id')->first();
        if ($unpaid !== null) {
            app(UseCredit::class)->apply($pallets->refresh(), $unpaid, $this->owner());
        }
    }

    // -------------------------------------------------------------- quotes

    private function quotes(): void
    {
        $send = app(SendQuote::class);
        $sites = OrganizationLocation::query()->get()->keyBy('name');

        // Greenline: sent last week, revised today after they added soil.
        $depot = $sites['Greenline depot'];
        $order = $this->draft([
            'organization_id' => $this->orgs['greenline']->id,
            'customer_id' => $this->people['ali']->id,
            'organization_location_id' => $depot->id,
            'fulfillment' => 'delivery',
            'delivery_address' => $depot->address(),
            'requested_on' => $this->at(-9)->toDateString(),
        ], ['AGG-SCR@Cubic yard' => 18, 'MUL-BCD' => 12], $this->at(6, '14:00'), $this->owner());
        $send->handle($order, $this->owner(), CarbonImmutable::today(self::TZ)->addDays(24), email: false);
        $order->forceFill(['quoted_at' => $this->at(6, '14:10')])->save();
        $order->revisions()->update(['created_at' => $this->at(6, '14:10')]);
        app(AddOrderLine::class)->handle($order, $this->products['SOIL-3MX'], '4', null, $this->products['SOIL-3MX']->units()->where('name', 'Bulk bag')->firstOrFail());
        $send->handle($order->refresh(), $this->owner(), CarbonImmutable::today(self::TZ)->addDays(24), email: false);

        // Lakeshore: accepted online yesterday, waiting to be confirmed.
        $order = $this->draft([
            'organization_id' => $this->orgs['lakeshore']->id,
            'customer_id' => $this->people['sam']->id,
            ...['fulfillment' => 'delivery', 'delivery_address' => ['line1' => '41 Bayview Dr', 'city' => 'Burlington', 'region' => 'ON', 'postal_code' => 'L7T 3S2', 'country' => 'CA']],
        ], ['PAV-60CH@Pallet (115 sq ft)' => 4, 'SND-POLY' => 10, 'AGG-SCR' => 6], $this->at(3, '10:00'));
        $send->handle($order, $this->counter(), CarbonImmutable::today(self::TZ)->addDays(27), email: false);
        $order->forceFill(['quoted_at' => $this->at(3, '10:15'), 'quote_accepted_at' => $this->at(1, '19:42'), 'quote_accepted_by' => 'Sam Ferreira', 'po_number' => 'LPP-118'])->save();
        $order->revisions()->update(['created_at' => $this->at(3, '10:15')]);

        // Halton: a quote that ran out.
        $order = $this->draft([
            'organization_id' => $this->orgs['halton']->id,
            'customer_id' => $this->people['priya']->id,
        ], ['BRK-MOD' => 4, 'MOR-S30' => 40], $this->at(44, '15:30'));
        $send->handle($order, $this->counter(), CarbonImmutable::today(self::TZ)->subDays(14), email: false);
        $order->forceFill(['quoted_at' => $this->at(44, '15:40')])->save();
        $order->revisions()->update(['created_at' => $this->at(44, '15:40')]);
    }

    // ------------------------------------------------------------- rentals

    /** A hand-drawn signature for the demo agreements. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAPAAAABGCAQAAABYz9FxAAACl0lEQVR42u2bW3LDIAxFgyfLyP5X5n2kHx6PxzWYlySEc85XmyZG1UVCCPJ6AQAAAAAAAAAAAAAAAAAAwK/x+X6+eMEzoU/e/ac14MqHCbyJuwm7C43M/lj65X291oC0jxL4LO8RvazHjxA4Ji8J+mEpOiUmMTy9wHcSEsP+eEvG7zYBkLklYLS8FurNuTcFgVtzoY7fqgXOmZGfAhArVVOlq+kaXFpCjSi1Pt+6tmnt+3Xl/d8yGhTBZenXOobrU51Ncqz3kUYUh16jRkscT3Wp8a9/1UqNbf6RtyZIx6+l01LjSL2uZ3d6LOngWOqc2b+N0pd3742f19jtt3jXXGv9a/WkpCVqMamfpnMjXN2Ue7fFxMx7UjafLDryeoiDc7SWnHjpx3CJJ82LrFZ5dWNC/uk2Oae8ypexZZkxenXEsDnwLLFasiZY9OSdr581tmaotaSsVbPoRq9ORGgmU80YXoOULYe0OZnfmql5DZwPt06czXfXHXO8VZPeWSv3U3RiTTuCdSxuLVSPz7U0at76q5H0AeKs51UtNu9xfP+MNVRG8G9vZ2qfnz+y782IPZ8f1i/2vVstP1TJR+nYjaaL5py3BF1+LHq2Jf6fjl1SFv0hpFv5dh3j2mkbP+QYu/KbntqmxC6XzO5IIHfz7M5u2wsF97aaH3T3dm/sjiL7iib525Jp77kROC1R2Tptt5rd2aNbNLXJ6Ejg9qiwrUbTo8lMM2kh3QucF3DMZb54q7Du8tKoUtGZwPnEaL3ZKN0IeRByCoH9XIuLTas5v5XlzrBYpI7rBR0j+7z44KLRIVOAjHHtvm+fVV6HAl9bIWN7QcdlvTm/ceXU6PlTIwJXpGnkfaDAY4srAAAAAAAAAAAAABDkDz4VeEPQ4wq3AAAAAElFTkSuQmCC';

    /** @var array<string, RentalUnit> */
    private array $units = [];

    private function rentals(): void
    {
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
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '47 Townsend Ave', 'city' => 'Burlington', 'region' => 'ON', 'postal_code' => 'L7T 1Y8', 'country' => 'CA'],
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

    /**
     * Delivery zones, delivery pricing, trucks, and where things sit in
     * the Waterdown yard.
     */
    private function deliverySetup(Tenant $tenant): void
    {
        $tenant->update(['settings' => [...($tenant->settings ?? []), 'delivery' => [
            'method' => 'zone',
            'out_of_zone_cents' => 14500,
            'eta_texts' => true,
            'portal_stops_ahead' => true,
            'portal_truck_location' => true,
        ]]]);

        foreach ([
            ['Truck 1 · tandem', 'material', 'Dave Kowalchuk', 14000, false],
            ['Truck 2 · boom', 'material', 'Sam Rinaldi', 12000, true],
            ['Truck 3 · tri-axle', 'material', 'Luis Moreno', 20000, false],
            ['Float · equipment', 'equipment', 'Mike Tremblay', null, false],
        ] as $position => [$name, $kind, $driver, $capacity, $boom]) {
            $this->trucks[] = Truck::query()->create([
                'name' => $name,
                'kind' => $kind,
                'driver_name' => $driver,
                'driver_phone' => '905-555-01'.(70 + $position),
                // Sam signs in to the driver app; the others are names on the board.
                'driver_id' => $driver === 'Sam Rinaldi' ? $this->users[self::DEMO_DRIVER]->id : null,
                'capacity_kg' => $capacity,
                'has_boom' => $boom,
                'location_id' => $this->waterdown->id,
                'position' => $position,
            ]);
        }

        $bins = [
            'AGG-SCR' => 'Bay 4', 'AGG-34C' => 'Bay 5', 'AGG-RVR' => 'Bay 6', 'SOIL-3MX' => 'Bay 1', 'SOIL-GDN' => 'Bay 1B',
            'MUL-BCD' => 'Bay 2', 'PAV-60CH' => 'Row C', 'PAV-80NT' => 'Row C', 'FLG-ARM' => 'Row G', 'ARM-23' => 'North yard',
            'WAL-STD' => 'Row F', 'WAL-CAP' => 'Row F', 'BRK-MOD' => 'Row E', 'BLK-8IN' => 'Row D', 'MOR-S30' => 'Aisle A',
            'CEM-GU30' => 'Aisle A', 'SND-POLY' => 'Aisle B', 'FAB-GEO' => 'Aisle C',
        ];

        foreach ($bins as $sku => $bin) {
            StockLevel::query()->where('product_id', $this->products[$sku]->id)->where('location_id', $this->waterdown->id)->update(['bin' => $bin]);
        }

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
        $inspections = array_values($items->map(fn ($item) => new InspectionData(
            $item->id,
            $hours[$item->unit?->serial] ?? null,
            FuelLevel::Full,
            true,
            null,
        ))->all());

        app(CheckOutRental::class)->handle($rental->refresh(), $inspections, $this->yardLead(), $tender);
        $rental->forceFill(['checked_out_at' => $at])->save();

        // Delivered rentals: the drop-off run is done.
        Stop::query()->where('stoppable_type', 'rental')->where('stoppable_id', $rental->id)->where('kind', 'rental_drop')->update([
            'status' => 'done',
            'truck_id' => $this->trucks[3]->id,
            'date' => $at->toDateString(),
            'starts_at' => $at->setTimezone(self::TZ)->format('H:00'),
            'completed_at' => $at->addHour(),
        ]);
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
