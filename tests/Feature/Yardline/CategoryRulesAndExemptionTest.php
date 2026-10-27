<?php

namespace Tests\Feature\YardRunner;

use App\Access\AccessSettings;
use App\Enums\DiscountType;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\ContractPrice;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Pricing\PriceResolver;
use App\Pricing\PricingSettings;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Products exempt from price lists, and price list discounts on a whole
 * category: which rule wins, stacking on volume breaks, and who may set an
 * exempt product's price by hand.
 */
class CategoryRulesAndExemptionTest extends TestCase
{
    use RefreshDatabase;

    private Product $block;

    private ProductUnit $pallet;

    private PriceList $list;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);

        $this->signInToNewTenant();

        // $4.00 a block, $300 for a pallet of 90.
        $this->block = Product::factory()->create(['category' => 'Block', 'price_cents' => 400]);
        $this->pallet = $this->block->units()->create(['name' => 'Pallet (90)', 'factor' => 90, 'price_cents' => 30_000]);
        $this->list = PriceList::factory()->create();
        $this->org = Organization::factory()->create(['price_list_id' => $this->list->id]);
    }

    private function price(Product $product, string|float $quantity = 1, ?ProductUnit $unit = null): int
    {
        return app(PriceResolver::class)->unitPrice($product, $this->org->refresh(), $quantity, $unit)->cents;
    }

    private function percentOff(string $category, float $percent): void
    {
        $this->list->categoryRules()->create(['category' => $category, 'discount_type' => DiscountType::Percent, 'discount_percent' => $percent]);
    }

    public function test_percent_category_rule_discounts_every_unit(): void
    {
        $this->percentOff('block', 10); // matched without regard to case

        $this->assertSame(360, $this->price($this->block));
        $this->assertSame(27_000, $this->price($this->block, 1, $this->pallet));
    }

    public function test_dollar_category_rule_is_per_base_unit(): void
    {
        $this->list->categoryRules()->create(['category' => 'Block', 'discount_type' => DiscountType::Amount, 'discount_cents' => 50]);

        $this->assertSame(350, $this->price($this->block));
        $this->assertSame(30_000 - 50 * 90, $this->price($this->block, 1, $this->pallet));
    }

    public function test_discount_never_goes_below_zero(): void
    {
        $this->list->categoryRules()->create(['category' => 'Block', 'discount_type' => DiscountType::Amount, 'discount_cents' => 1_000]);

        $this->assertSame(0, $this->price($this->block));
    }

    public function test_product_price_wins_by_default(): void
    {
        $this->list->items()->create(['product_id' => $this->block->id, 'min_quantity' => 1, 'price_cents' => 380]);
        $this->percentOff('Block', 20);

        $this->assertSame(380, $this->price($this->block));

        // Other products in the category still get the category discount.
        $cap = Product::factory()->create(['category' => 'Block', 'price_cents' => 1_000]);
        $this->assertSame(800, $this->price($cap));
    }

    public function test_tenant_can_make_category_rules_win(): void
    {
        $this->tenant()->update(['settings' => ['pricing' => ['category_rules_override' => true]]]);
        $this->list->items()->create(['product_id' => $this->block->id, 'min_quantity' => 1, 'price_cents' => 380]);
        $this->percentOff('Block', 20);

        $this->assertSame(320, $this->price($this->block));
    }

    public function test_price_list_overrides_the_tenant_default(): void
    {
        $this->tenant()->update(['settings' => ['pricing' => ['category_rules_override' => true]]]);
        $this->list->update(['category_rules_override' => false]);
        $this->list->items()->create(['product_id' => $this->block->id, 'min_quantity' => 1, 'price_cents' => 380]);
        $this->percentOff('Block', 20);

        $this->assertSame(380, $this->price($this->block));
    }

    public function test_category_discount_stacks_on_volume_breaks_when_the_list_says_so(): void
    {
        $this->list->items()->create(['product_id' => $this->block->id, 'min_quantity' => 1, 'price_cents' => 380]);
        $this->list->items()->create(['product_id' => $this->block->id, 'min_quantity' => 500, 'price_cents' => 340]);
        $this->percentOff('Block', 10);

        $this->assertSame(340, $this->price($this->block, 600));

        $this->list->update(['stack_volume_breaks' => true]);

        $this->assertSame(306, $this->price($this->block, 600));
        // Below the break there's nothing to stack on: the product price wins as usual.
        $this->assertSame(380, $this->price($this->block, 10));
    }

    public function test_exempt_products_ignore_price_lists_contracts_and_category_rules(): void
    {
        $this->block->update(['price_list_exempt' => true]);
        $this->list->items()->create(['product_id' => $this->block->id, 'min_quantity' => 1, 'price_cents' => 380]);
        $this->percentOff('Block', 20);
        ContractPrice::query()->create(['organization_id' => $this->org->id, 'product_id' => $this->block->id, 'price_cents' => 300]);

        $resolved = app(PriceResolver::class)->unitPrice($this->block->refresh(), $this->org, 1);

        $this->assertSame(400, $resolved->cents);
        $this->assertSame('exempt', $resolved->source);
        $this->assertSame(30_000, $this->price($this->block, 1, $this->pallet));
    }

    public function test_order_lines_record_the_category_rule(): void
    {
        $this->percentOff('Block', 10);
        $order = $this->draft();

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->block->id, 'quantity' => 10])->assertSessionHasNoErrors();

        $line = $order->lines()->firstOrFail();
        $this->assertSame(360, $line->unit_price_cents);
        $this->assertSame('category', $line->price_source);
        $this->assertSame($this->list->categoryRules()->value('id'), $line->category_rule_id);
    }

    public function test_by_default_only_the_owner_can_price_an_exempt_product_and_must_say_why(): void
    {
        $this->block->update(['price_list_exempt' => true]);
        $order = $this->draft();

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->block->id, 'quantity' => 10, 'unit_price' => '3.50'])
            ->assertSessionHasErrors('price_reason');

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->block->id, 'quantity' => 10, 'unit_price' => '3.50', 'price_reason' => 'Damaged cubes'])
            ->assertSessionHasNoErrors();

        $this->assertSame(350, $order->lines()->value('unit_price_cents'));
        $event = AuditEvent::query()->where('subject_type', 'order')->where('subject_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame([400, 350], $event->changes['exempt_price_cents']);
        $this->assertStringContainsString('Damaged cubes', (string) $event->note);
    }

    public function test_tenant_chooses_which_roles_can_price_exempt_products(): void
    {
        $this->block->update(['price_list_exempt' => true]);
        $tenant = $this->tenant();
        $admin = $this->signInAs(UserRole::Admin);
        $order = $this->draft();

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->block->id, 'quantity' => 10, 'unit_price' => '3.50', 'price_reason' => 'Damaged'])
            ->assertSessionHasErrors('unit_price');

        // The business gives the Admin role Discount exempt products.
        $tenant->update(['settings' => ['access' => ['role_permissions' => ['admin' => ['set_manual_prices', 'discount_exempt']]]]]);
        $this->assertTrue(PricingSettings::for($tenant->refresh())->canPriceExempt($admin));

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->block->id, 'quantity' => 10, 'unit_price' => '3.50', 'price_reason' => 'Damaged'])
            ->assertSessionHasNoErrors();
    }

    public function test_owner_saves_pricing_rules(): void
    {
        $this->put(route('pricing-settings.update'), ['category_rules_override' => true, 'exempt_price_roles' => ['admin']])
            ->assertSessionHasNoErrors();

        $settings = PricingSettings::for($this->tenant());
        $this->assertTrue($settings->categoryRulesOverride);
        // The owner always can; the Admin role now gives it too.
        $this->assertSame([UserRole::Owner, UserRole::Admin], $settings->exemptPriceRoles);
        $this->assertTrue(AccessSettings::for($this->tenant())->roleGives(UserRole::Admin, Permission::DiscountExempt));

        $this->put(route('pricing-settings.update'), ['exempt_price_roles' => ['sales']])
            ->assertSessionHasErrors('exempt_price_roles.0');
    }

    public function test_saving_a_category_rule_again_replaces_it(): void
    {
        $this->post(route('price-lists.category-rules.store', $this->list), ['category' => 'Block', 'discount_type' => 'percent', 'discount' => 10])
            ->assertSessionHasNoErrors();
        $this->post(route('price-lists.category-rules.store', $this->list), ['category' => ' block ', 'discount_type' => 'amount', 'discount' => '0.25'])
            ->assertSessionHasNoErrors();

        $rule = $this->list->categoryRules()->sole();
        $this->assertSame(DiscountType::Amount, $rule->discount_type);
        $this->assertSame(25, $rule->discount_cents);
        $this->assertNull($rule->discount_percent);

        $this->post(route('price-lists.category-rules.store', $this->list), ['category' => 'Block', 'discount_type' => 'percent', 'discount' => 120])
            ->assertSessionHasErrors('discount');
    }

    private function draft(): Order
    {
        $this->post(route('orders.store'), [
            'type' => 'sale',
            'organization_id' => $this->org->id,
            'location_id' => Location::query()->firstOrFail()->id,
            'fulfillment' => 'pickup',
        ])->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail(CurrentTenant::id());
    }

    private function signInAs(UserRole $role): User
    {
        $user = User::factory()->create(['tenant_id' => CurrentTenant::id(), 'role' => $role]);
        $this->actingAs($user);

        return $user;
    }
}
