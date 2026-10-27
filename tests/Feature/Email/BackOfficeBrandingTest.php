<?php

namespace Tests\Feature\Email;

use App\Email\BrandSettings;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The colour system and the "Sent with YardRunner" footer are switched in
 * the back office only.
 */
class BackOfficeBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_switch_the_colour_system_and_footer(): void
    {
        $tenant = Tenant::factory()->create();
        CurrentTenant::run($tenant, fn () => Location::factory()->create(['is_default' => true]));
        BrandSettings::save($tenant, ['primary' => '#1F5C99']);

        $staff = User::factory()->state(['tenant_id' => null, 'role' => UserRole::Sales])->withTwoFactor()->create();
        $staff->forceFill(['is_platform_staff' => true])->save();
        $this->actingAs($staff);

        $this->get("/admin/tenants/{$tenant->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.branding.colour_system', false)
                ->where('tenant.branding.sent_with_yardrunner', true));

        $this->patch("/admin/tenants/{$tenant->id}", ['colour_system' => true, 'sent_with_yardrunner' => false])->assertSessionHasNoErrors();

        $brand = BrandSettings::for($tenant->refresh());
        $this->assertTrue($brand->colourSystem);
        $this->assertFalse($brand->sentWithYardRunner);
        $this->assertSame('#1F5C99', $brand->primary, 'the rest of their branding is kept');

        $event = AuditEvent::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
        $this->assertSame([false, true], $event->changes['colour_system'] ?? null);
        $this->assertSame([true, false], $event->changes['sent_with_yardrunner'] ?? null);
        $this->assertArrayNotHasKey('settings', $event->changes ?? []);
    }
}
