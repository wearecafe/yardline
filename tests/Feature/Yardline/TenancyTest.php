<?php

namespace Tests\Feature\Yardline;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantNotResolved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_queries_only_see_the_current_tenants_rows(): void
    {
        [$a, $b] = Tenant::factory()->count(2)->create();

        CurrentTenant::run($a, fn () => Product::factory()->create(['sku' => 'A-1']));
        CurrentTenant::run($b, fn () => Product::factory()->create(['sku' => 'B-1']));

        CurrentTenant::set($a);
        $this->assertSame(['A-1'], Product::query()->pluck('sku')->all());

        CurrentTenant::set($b);
        $this->assertSame(['B-1'], Product::query()->pluck('sku')->all());
    }

    public function test_no_tenant_means_no_rows(): void
    {
        $tenant = Tenant::factory()->create();
        CurrentTenant::run($tenant, fn () => Product::factory()->create());

        CurrentTenant::set(null);

        $this->assertSame(0, Product::query()->count());
    }

    public function test_creating_a_tenant_model_without_a_tenant_fails(): void
    {
        $this->expectException(TenantNotResolved::class);

        CurrentTenant::set(null);
        Product::query()->create(['sku' => 'X', 'name' => 'Orphan', 'price_cents' => 100]);
    }

    public function test_route_model_binding_hides_other_tenants_records(): void
    {
        $other = Tenant::factory()->create();
        $foreign = CurrentTenant::run($other, fn () => Product::factory()->create());

        $this->signInToNewTenant();

        $this->get(route('products.edit', $foreign->id))->assertNotFound();
    }

    public function test_foreign_keys_from_another_tenant_are_rejected(): void
    {
        $other = Tenant::factory()->create();
        $foreignOrg = CurrentTenant::run($other, fn () => Organization::factory()->create());

        $user = $this->signInToNewTenant();

        $this->post(route('customers.store'), [
            'first_name' => 'Sneaky',
            'organization_id' => $foreignOrg->id,
        ])->assertSessionHasErrors('organization_id');

        $this->assertInstanceOf(User::class, $user);
    }
}
