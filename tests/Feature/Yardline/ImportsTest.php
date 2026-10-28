<?php

namespace Tests\Feature\YardRunner;

use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Enums\PaymentTerms;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Imports\ImportKind;
use App\Inventory\InventoryLedger;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Import;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLocation;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Receivables\Aging;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Loading a yard's existing data from CSV: checked first (the preview and
 * error report), then imported all or nothing, in order: products and
 * units, price lists, accounts and contacts, job sites, opening stock and
 * opening balances.
 */
class ImportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Location $yard;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);
        Storage::fake((string) config('yardline.files_disk'));

        $this->owner = $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
        $this->yard->update(['name' => 'Waterdown', 'code' => 'WD']);
    }

    private function upload(ImportKind $kind, string $csv): Import
    {
        $this->post(route('imports.store'), [
            'kind' => $kind->value,
            'file' => UploadedFile::fake()->createWithContent("{$kind->value}.csv", $csv),
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Import::query()->latest('id')->firstOrFail();
    }

    private function run(Import $import, bool $leaveOutErrors = false): void
    {
        $this->post(route('imports.run', $import), ['leave_out_errors' => $leaveOutErrors])->assertSessionHasNoErrors();
    }

    private const PRODUCTS = <<<'CSV'
        sku,name,unit,base_unit,per_base,price,category,cost,per_pallet,pallet_deposit
        POLY-30,Polymeric sand 30 kg,,bag,,34.99,Joint sand,21.50,56,yes
        POLY-30,,Pallet,,56,1799.00,,,,
        SCR-A,Screenings,,tonne,,42.00,Aggregate,,,
        SCR-A,,Cubic yard,,1.35,55.00,,,,
        CSV;

    public function test_the_import_page_and_templates(): void
    {
        $this->get(route('imports.index', ['kind' => 'stock', 'setup' => 1]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/imports')
            ->has('kinds', 6)
            ->where('selected', 'stock')
            ->where('fromSetup', true));

        // The template is just the headings; the sample has worked rows.
        $template = (string) $this->get(route('imports.template', 'products'))->assertOk()->assertDownload('yardrunner-products-template.csv')->getContent();
        $this->assertStringStartsWith('sku,name,unit,base_unit,per_base,price', $template);
        $this->assertCount(1, array_filter(explode("\n", $template)));

        $sample = (string) $this->get(route('imports.sample', 'products'))->assertOk()->assertDownload('yardrunner-products-sample.csv')->getContent();
        $this->assertStringContainsString('POLY-30,"Polymeric sand 30 kg"', $sample);
    }

    public function test_the_six_samples_load_in_order_without_errors(): void
    {
        $this->yard->update(['name' => 'Main yard']);

        foreach (ImportKind::cases() as $kind) {
            $import = $this->upload($kind, $kind->sample());
            $this->assertSame(0, $import->error_rows, "{$kind->value}: ".json_encode($import->errors));
            $this->run($import);
            $this->assertSame(Import::IMPORTED, $import->refresh()->status);
        }

        $this->assertSame(6, Product::query()->count());
        $this->assertSame(3, Organization::query()->count());
        $this->assertSame(3, Invoice::query()->where('number', 'like', 'OB-%')->count());
        $this->assertSame('2300.000', StockLevel::query()->whereHas('product', fn ($q) => $q->where('sku', 'HOLL-60'))->sole()->on_hand);
    }

    public function test_only_owners_and_admins_import(): void
    {
        $sales = User::factory()->create(['tenant_id' => $this->owner->tenant_id]);
        $sales->setRoles([UserRole::Sales])->save();
        $this->actingAs($sales->refresh());

        $this->post(route('imports.store'), ['kind' => 'products', 'file' => UploadedFile::fake()->createWithContent('p.csv', self::PRODUCTS)])
            ->assertForbidden();
    }

    public function test_products_and_units_are_checked_then_imported(): void
    {
        $import = $this->upload(ImportKind::Products, self::PRODUCTS);

        // Checked, not imported.
        $this->assertSame(Import::CHECKED, $import->status);
        $this->assertSame([4, 0, 4], [$import->total_rows, $import->error_rows, $import->created_count]);
        $this->assertSame(0, Product::query()->count());
        $this->get(route('imports.show', $import))->assertInertia(fn (Assert $page) => $page
            ->component('settings/import')
            ->has('import.preview', 4)
            ->where('import.preview.1.action', 'create'));

        $this->run($import);

        $poly = Product::query()->where('sku', 'POLY-30')->sole();
        $this->assertSame(['Polymeric sand 30 kg', Unit::Bag, 3_499, 2_150, 'Joint sand', true], [$poly->name, $poly->unit, $poly->price_cents, $poly->cost_cents, $poly->category, $poly->pallet_deposit]);
        $this->assertSame('56.000', $poly->per_pallet);
        $pallet = $poly->units()->where('name', 'Pallet')->sole();
        $this->assertSame([56.0, 179_900], [(float) $pallet->factor, $pallet->price_cents]);
        $this->assertSame(1.35, (float) Product::query()->where('sku', 'SCR-A')->sole()->units()->where('name', 'Cubic yard')->sole()->factor);
        $this->assertSame(Import::IMPORTED, $import->refresh()->status);

        // The same file again, with a new price: updated, not doubled.
        $again = $this->upload(ImportKind::Products, str_replace('34.99', '36.99', self::PRODUCTS));
        $this->assertSame([0, 4], [$again->created_count, $again->updated_count]);
        $this->run($again);
        $this->assertSame(2, Product::query()->count());
        $this->assertSame(3_699, $poly->refresh()->price_cents);
        $this->assertSame(2, $poly->units()->count());
    }

    public function test_rows_with_errors_stop_the_import_unless_left_out(): void
    {
        $csv = <<<'CSV'
            SKU;Name;Unit;Base Unit;Per Base;Price
            GOOD-1;Edging;;each;;12.00
            BAD-1;;Pallet;;56;99.00
            BAD-2;Pavers;;skids and stuff;;
            CSV;
        // A byte-order mark and semicolons, as Excel sometimes saves.
        $import = $this->upload(ImportKind::Products, "\xEF\xBB\xBF".$csv);

        $this->assertSame([3, 2], [$import->total_rows, $import->error_rows]);
        $messages = collect($import->errors)->pluck('message')->implode(' ');
        $this->assertStringContainsString('BAD-1 isn’t a product yet', $messages);
        $this->assertStringContainsString('Use each, bag, pallet', $messages);
        $this->assertStringContainsString('Price is required', $messages);

        // The error report: just the failing rows, with what's wrong.
        $report = (string) $this->get(route('imports.errors', $import))->assertOk()->getContent();
        $this->assertStringContainsString('line,errors,sku', $report);
        $this->assertStringContainsString('3,"sku: BAD-1 isn’t a product yet', $report);
        $this->assertStringNotContainsString('GOOD-1', $report);

        // All or nothing.
        $this->post(route('imports.run', $import))->assertSessionHasErrors('import');
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(Import::CHECKED, $import->refresh()->status);

        $this->run($import, leaveOutErrors: true);
        $this->assertSame(['GOOD-1'], Product::query()->pluck('sku')->all());
        $this->assertSame(Import::IMPORTED, $import->refresh()->status);

        // Imported once only.
        $this->post(route('imports.run', $import))->assertSessionHasErrors('import');
    }

    public function test_a_file_without_a_required_column_is_refused(): void
    {
        $import = $this->upload(ImportKind::Stock, "sku,quantity\nPOLY-30,4\n");

        $this->assertSame('The file needs a yard column.', $import->errors[0]['message'] ?? null);
        $this->post(route('imports.run', $import))->assertSessionHasErrors('import');
    }

    public function test_price_lists_take_prices_breaks_and_category_discounts(): void
    {
        $this->run($this->upload(ImportKind::Products, self::PRODUCTS));

        $import = $this->upload(ImportKind::PriceLists, <<<'CSV'
            price_list,sku,unit,price,min_quantity,category,discount_percent,discount_amount
            Contractor,POLY-30,,31.50,,,,
            Contractor,POLY-30,Pallet,1650.00,,,,
            Contractor,SCR-A,,38.00,20,,,
            Builder,,,,,Aggregate,10,
            Builder,NOPE,,10,,,,
            CSV);

        $this->assertSame(1, $import->error_rows);
        $this->run($import, leaveOutErrors: true);

        $contractor = PriceList::query()->where('name', 'Contractor')->sole();
        $this->assertSame(3, $contractor->items()->count());
        $this->assertSame(165_000, $contractor->items()->whereNotNull('product_unit_id')->sole()->price_cents);
        $this->assertSame(20.0, (float) $contractor->items()->where('price_cents', 3_800)->sole()->min_quantity);
        $rule = PriceList::query()->where('name', 'Builder')->sole()->categoryRules()->sole();
        $type = $rule->discount_type instanceof DiscountType ? $rule->discount_type->value : $rule->discount_type;
        $this->assertSame(['Aggregate', 'percent', 10.0], [$rule->category, $type, (float) $rule->discount_percent]);
    }

    public function test_accounts_and_their_contacts(): void
    {
        PriceList::query()->create(['name' => 'Contractor']);

        $import = $this->upload(ImportKind::Organizations, <<<'CSV'
            name,account_number,email,terms,credit_limit,price_list,contact_first_name,contact_last_name,contact_email,contact_role,can_charge
            Brantwood Landscaping,C-10021,AP@brantwood.example,net 30,"25,000",Contractor,Maria,Rossi,maria@brantwood.example,admin,
            Brantwood Landscaping,C-10021,,,,,Dev,Patel,,,no
            Ridgeline Hardscapes,,not-an-email,net 45,,,,,,,
            CSV);

        $this->assertSame([3, 1], [$import->total_rows, $import->error_rows]);
        $this->assertSame(1, $import->created_count);
        $this->run($import, leaveOutErrors: true);

        $org = Organization::query()->where('account_number', 'C-10021')->sole();
        $this->assertSame([PaymentTerms::Net30, 2_500_000, 'ap@brantwood.example'], [$org->payment_terms, $org->credit_limit_cents, $org->email]);
        $this->assertNotNull($org->price_list_id);
        $contacts = $org->contacts()->orderBy('first_name')->get();
        $this->assertSame(['Dev', 'Maria'], $contacts->pluck('first_name')->all());
        $this->assertSame(['buyer', 'admin'], $contacts->map(fn (Customer $c) => $c->membership->role)->all());
        $this->assertFalse((bool) $contacts->first()->membership->can_charge_account);
    }

    public function test_job_sites_by_account_number_and_yard_code(): void
    {
        $org = Organization::factory()->create(['name' => 'Brantwood Landscaping', 'account_number' => 'C-10021']);

        $this->run($this->upload(ImportKind::JobSites, <<<'CSV'
            account,name,address_line1,city,region,postal_code,yard,po_required,default
            C-10021,Maple Ridge phase 2,48 Orchard Cres,Waterdown,ON,L8B 0C2,WD,yes,yes
            Brantwood Landscaping,Shop,1 Industrial Rd,Hamilton,ON,L8E 2N1,,,
            CSV));

        $sites = OrganizationLocation::query()->where('organization_id', $org->id)->orderBy('name')->get();
        $this->assertSame(['Maple Ridge phase 2', 'Shop'], $sites->pluck('name')->all());
        $this->assertSame([$this->yard->id, true, true], [$sites[0]->location_id, $sites[0]->po_required, $sites[0]->is_default]);
    }

    public function test_opening_stock_sets_on_hand_and_cost_without_touching_profit(): void
    {
        $this->run($this->upload(ImportKind::Products, self::PRODUCTS));
        $poly = Product::query()->where('sku', 'POLY-30')->sole();

        $this->run($this->upload(ImportKind::Stock, <<<'CSV'
            sku,yard,quantity,unit_cost,bin,reorder_point
            POLY-30,Waterdown,420,22.00,Row C,60
            SCR-A,wd,180.5,,,
            CSV));

        $level = StockLevel::query()->where('product_id', $poly->id)->sole();
        $this->assertSame(['420.000', 'Row C', '60.000', '2200.0000'], [$level->on_hand, $level->bin, $level->reorder_point, $level->average_cost_cents]);
        $this->assertSame(StockMovementType::Receipt, StockMovement::query()->where('product_id', $poly->id)->sole()->type);

        // Loading it again sets the count; it doesn't add.
        $this->run($this->upload(ImportKind::Stock, "sku,yard,quantity\nPOLY-30,Waterdown,400\n"));
        $this->assertSame('400.000', app(InventoryLedger::class)->onHand($poly, $this->yard));
    }

    public function test_opening_balances_carry_on_as_invoices_and_credits(): void
    {
        $org = Organization::factory()->withCredit(10_000_000, PaymentTerms::Net30)->create(['account_number' => 'C-10021']);
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';
        $issued = now($tz)->subDays(45)->toDateString();

        $csv = "account,invoice_number,issued_on,amount,due_on\n"
            ."C-10021,48812,{$issued},\"4,812.40\",\n"
            ."C-10021,CM-311,{$issued},(120.00),\n"
            ."C-10021,48900,15/09/2026,10,\n";

        $import = $this->upload(ImportKind::Balances, $csv);
        $this->assertSame(1, $import->error_rows);
        $this->assertStringContainsString('Use a date like', collect($import->errors)->pluck('message')->implode(' '));
        $this->run($import, leaveOutErrors: true);

        $invoice = Invoice::query()->where('number', 'OB-48812')->sole();
        $this->assertSame(now($tz)->subDays(15)->toDateString(), $invoice->due_on->toDateString());
        $this->assertSame(481_240, $invoice->balanceCents());
        $this->assertSame(OrderType::OpeningBalance, $invoice->order->type);

        $credit = CreditNote::query()->where('number', 'OC-CM-311')->sole();
        $this->assertSame(['opening', 12_000], [$credit->kind, $credit->remainingCents()]);

        // It ages like any invoice, net of the credit.
        $row = app(Aging::class)->byOrganization()->firstWhere('organization_id', $org->id);
        $this->assertSame([481_240, 12_000], [$row['buckets']['d30'], $row['credit_cents']]);

        // Paid like any invoice.
        $this->post(route('orders.payments.store', $invoice->order), ['tender' => 'cheque', 'amount' => '4812.40'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->refresh()->status);

        // Loading the same file again skips what's there.
        $again = $this->upload(ImportKind::Balances, $csv);
        $this->assertSame(2, $again->skipped_count);
        $this->run($again, leaveOutErrors: true);
        $this->assertSame(1, Order::query()->where('type', OrderType::OpeningBalance->value)->count());
    }

    public function test_setup_points_to_the_import(): void
    {
        $this->get(route('setup.step', 'products'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('setup/products'));
    }
}
