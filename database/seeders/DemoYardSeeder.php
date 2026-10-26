<?php

namespace Database\Seeders;

use App\Enums\PaymentTerms;
use App\Enums\ProductKind;
use App\Enums\RentalPeriod;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Inventory\InventoryLedger;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Orders\AddOrderLine;
use App\Orders\CreateOrder;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;

/**
 * A believable masonry and landscape supply yard for local development.
 * Sign in as owner@yardline.test / password.
 */
class DemoYardSeeder extends Seeder
{
    public function run(InventoryLedger $ledger, CreateOrder $createOrder, AddOrderLine $addLine): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Escarpment Stone & Landscape Supply',
            'slug' => 'escarpment-stone',
        ]);

        CurrentTenant::run($tenant, function () use ($tenant, $ledger, $createOrder, $addLine) {
            $owner = User::factory()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Yard Owner',
                'email' => 'owner@yardline.test',
                'role' => UserRole::Owner,
            ]);

            User::factory()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Counter Staff',
                'email' => 'counter@yardline.test',
                'role' => UserRole::Staff,
            ]);

            $yard = Location::query()->create([
                'name' => 'Waterdown yard',
                'code' => 'WTD',
                'address_line1' => '100 Dundas St E',
                'city' => 'Waterdown',
                'region' => 'ON',
                'postal_code' => 'L9H 7L6',
                'country' => 'CA',
                'is_default' => true,
            ]);

            $products = collect([
                ['AGG-SCR', 'Limestone screenings', 'Aggregates', Unit::Tonne, 4200, 120],
                ['AGG-34C', '3/4" clear stone', 'Aggregates', Unit::Tonne, 4800, 90],
                ['SOIL-3MX', 'Triple mix soil', 'Soil & mulch', Unit::CubicYard, 5500, 60],
                ['MUL-BCD', 'Black cedar mulch', 'Soil & mulch', Unit::CubicYard, 6200, 45],
                ['PAV-60CH', 'Paver, 60 mm, charcoal', 'Pavers', Unit::SquareFoot, 545, 4000],
                ['PAV-80NT', 'Paver, 80 mm, natural', 'Pavers', Unit::SquareFoot, 625, 2500],
                ['WAL-STD', 'Retaining wall block, standard', 'Walls', Unit::Each, 1295, 600],
                ['WAL-CAP', 'Retaining wall cap', 'Walls', Unit::Each, 1850, 220],
                ['ARM-23', 'Armour stone, 2–3 ft', 'Natural stone', Unit::Tonne, 18500, 40],
                ['BRK-MOD', 'Clay brick, modular, red range', 'Brick & block', Unit::Cube, 129500, 18],
                ['BLK-8IN', 'Concrete block, 8 in', 'Brick & block', Unit::Each, 349, 1200],
                ['MOR-S30', 'Type S mortar, 30 kg', 'Mortar & cement', Unit::Bag, 1695, 300],
                ['CEM-GU30', 'Portland cement GU, 30 kg', 'Mortar & cement', Unit::Bag, 1495, 260],
                ['SND-POLY', 'Polymeric sand, 25 kg', 'Mortar & cement', Unit::Bag, 3995, 150],
            ])->map(fn (array $row) => tap(Product::query()->create([
                'sku' => $row[0],
                'name' => $row[1],
                'category' => $row[2],
                'unit' => $row[3],
                'price_cents' => $row[4],
                'cost_cents' => (int) round($row[4] * 0.62),
                'kind' => ProductKind::Sale,
            ]), fn (Product $product) => $ledger->record($product, $yard, $row[5], StockMovementType::Receipt, user: $owner, note: 'Opening stock')))
                ->keyBy('sku');

            foreach ([
                ['RNT-PLATE', 'Plate compactor', 9500, 25000, 3],
                ['RNT-SAW14', 'Masonry saw, 14 in', 8500, 30000, 2],
                ['RNT-MIXER', 'Mortar mixer', 7500, 20000, 2],
            ] as [$sku, $name, $rate, $deposit, $qty]) {
                $rental = Product::query()->create([
                    'sku' => $sku,
                    'name' => $name,
                    'category' => 'Equipment rental',
                    'kind' => ProductKind::Rental,
                    'unit' => Unit::Each,
                    'rental_period' => RentalPeriod::Day,
                    'price_cents' => $rate,
                    'deposit_cents' => $deposit,
                ]);
                $ledger->record($rental, $yard, $qty, StockMovementType::Receipt, user: $owner, note: 'Rental fleet');
            }

            $contractor = PriceList::query()->create([
                'name' => 'Contractor',
                'description' => 'Trade pricing for landscapers and masons on account.',
            ]);

            foreach (['PAV-60CH' => 490, 'PAV-80NT' => 565, 'WAL-STD' => 1165, 'MOR-S30' => 1525, 'SOIL-3MX' => 4950] as $sku => $price) {
                $contractor->items()->create(['product_id' => $products[$sku]->id, 'min_quantity' => 1, 'price_cents' => $price]);
            }
            // Volume break on screenings.
            $contractor->items()->create(['product_id' => $products['AGG-SCR']->id, 'min_quantity' => 1, 'price_cents' => 3900]);
            $contractor->items()->create(['product_id' => $products['AGG-SCR']->id, 'min_quantity' => 20, 'price_cents' => 3500]);

            $brantwood = Organization::query()->create([
                'name' => 'Brantwood Landscaping',
                'account_number' => 'C-10021',
                'email' => 'office@brantwood.test',
                'phone' => '905-555-0142',
                'address_line1' => '22 Mill St N',
                'city' => 'Waterdown',
                'region' => 'ON',
                'postal_code' => 'L8B 1A1',
                'price_list_id' => $contractor->id,
                'credit_limit_cents' => 1_500_000,
                'payment_terms' => PaymentTerms::Net30,
            ]);

            $halton = Organization::query()->create([
                'name' => 'Halton Masonry Ltd.',
                'account_number' => 'C-10034',
                'email' => 'ap@haltonmasonry.test',
                'price_list_id' => $contractor->id,
                'credit_limit_cents' => 500_000,
                'payment_terms' => PaymentTerms::Net15,
            ]);

            Organization::query()->create([
                'name' => 'Cedar Ridge Homes',
                'account_number' => 'C-10057',
                'credit_limit_cents' => 2_500_000,
                'payment_terms' => PaymentTerms::Net30,
                'credit_hold' => true,
                'notes' => 'On hold until March statement is cleared.',
            ]);

            $mike = Customer::query()->create(['organization_id' => $brantwood->id, 'first_name' => 'Mike', 'last_name' => 'Brant', 'phone' => '905-555-0143', 'email' => 'mike@brantwood.test']);
            Customer::query()->create(['organization_id' => $halton->id, 'first_name' => 'Priya', 'last_name' => 'Singh', 'phone' => '905-555-0177']);
            Customer::query()->create(['first_name' => 'Dana', 'last_name' => 'Kowalski', 'phone' => '289-555-0110', 'email' => 'dana.k@example.test']);

            $order = $createOrder->handle([
                'type' => 'sale',
                'organization_id' => $brantwood->id,
                'customer_id' => $mike->id,
                'location_id' => $yard->id,
                'fulfillment' => 'delivery',
                'delivery_address' => ['line1' => '48 Parkside Dr', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L8B 0A9', 'country' => 'CA'],
                'requested_on' => now()->addDays(2)->toDateString(),
                'po_number' => 'BW-PARKSIDE',
            ], $owner);

            $addLine->handle($order, $products['PAV-60CH'], 640);
            $addLine->handle($order, $products['SND-POLY'], 8);
            $addLine->handle($order, $products['AGG-SCR'], 22);
        });
    }
}
