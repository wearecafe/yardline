<?php

namespace Tests;

use App\Accounting\QuickBooks\QuickBooks;
use App\Audit\Audit;
use App\Email\Domains\DnsLookup;
use App\Email\Domains\FakeDomainProvider;
use App\Email\Domains\LocalDnsZone;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        CurrentTenant::set(null);
        // Static state that would otherwise leak into the next test.
        Audit::$customerId = null;
        QuickBooks::forget();
        DnsLookup::forget();
        LocalDnsZone::$records = [];
        FakeDomainProvider::$verifies = true;

        parent::tearDown();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Create a business with a yard, make it the current tenant, and sign in.
     */
    protected function signInToNewTenant(UserRole $role = UserRole::Owner): User
    {
        $tenant = Tenant::factory()->create();
        CurrentTenant::set($tenant);

        $yard = Location::factory()->create(['is_default' => true]);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);

        // Everyone but owners and admins works the yards they're given.
        if ($user->isYardScoped()) {
            $user->setYards([$yard->id]);
        }

        $this->actingAs($user);

        return $user;
    }
}
