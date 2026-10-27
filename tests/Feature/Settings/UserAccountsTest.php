<?php

namespace Tests\Feature\Settings;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\UserInviteMessage;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Settings → Users: anyone with Manage users and roles (owner and admin)
 * adds people with one or more roles, the yards they work and any
 * permissions on top, and removes them. People who only drive stay on
 * their own page.
 */
class UserAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->signInToNewTenant();
        $this->owner->update(['name' => 'Olivia Owner']);
        $this->tenant = CurrentTenant::get();
    }

    public function test_owners_add_users_who_choose_a_password(): void
    {
        Notification::fake();

        $this->post(route('users.store'), ['name' => 'Jess Palmer', 'email' => 'jess@example.com', 'roles' => ['admin']])
            ->assertSessionHasNoErrors();

        $jess = User::query()->where('email', 'jess@example.com')->firstOrFail();
        $this->assertSame(UserRole::Admin, $jess->role);
        $this->assertSame([UserRole::Admin], $jess->roleList());
        $this->assertSame($this->tenant->id, $jess->tenant_id);
        $this->assertNull($jess->email_verified_at);
        Notification::assertSentTo($jess, UserInviteMessage::class);

        $this->get(route('users.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/users')
            ->has('users', 2)
            ->where('users.0.name', 'Olivia Owner')
            ->where('users.0.is_you', true)
            ->where('users.0.manageable', false)
            ->where('users.1.name', 'Jess Palmer')
            ->where('users.1.roles', ['admin'])
            ->where('users.1.joined', false)
            ->where('users.1.manageable', true)
            ->has('roles', 10)
            ->has('permissions', count(Permission::cases())));

        $this->assertTrue(AuditEvent::query()->where('subject_type', 'user')->where('subject_id', $jess->id)->where('event', 'created')->exists());

        // Choosing a password from the link verifies their email.
        auth()->logout();
        $token = Password::broker()->createToken($jess);
        $this->post(route('password.update'), ['token' => $token, 'email' => 'jess@example.com', 'password' => 'a-Strong-pass-42!', 'password_confirmation' => 'a-Strong-pass-42!'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($jess->refresh()->email_verified_at);
    }

    public function test_people_can_hold_several_roles_and_work_some_yards(): void
    {
        $waterdown = Location::factory()->create(['name' => 'Waterdown']);
        $stoney = Location::factory()->create(['name' => 'Stoney Creek']);

        $this->post(route('users.store'), [
            'name' => 'Casey Counter',
            'email' => 'casey@example.com',
            'roles' => ['dispatcher', 'sales'],
            'location_ids' => [$waterdown->id],
        ])->assertSessionHasNoErrors();

        $casey = User::query()->where('email', 'casey@example.com')->firstOrFail();
        // The highest role is their main one; they hold both.
        $this->assertSame(UserRole::Sales, $casey->role);
        $this->assertSame([UserRole::Sales, UserRole::Dispatcher], $casey->roleList());
        $this->assertSame([$waterdown->id], $casey->yardIds());
        $this->assertTrue($casey->worksAt($waterdown->id));
        $this->assertFalse($casey->worksAt($stoney->id));

        // Someone added without yards starts with every yard.
        $this->post(route('users.store'), ['name' => 'Pat', 'email' => 'pat@example.com', 'roles' => ['yard_crew']])->assertSessionHasNoErrors();
        $pat = User::query()->where('email', 'pat@example.com')->firstOrFail();
        $this->assertEqualsCanonicalizing(Location::query()->pluck('id')->all(), $pat->yardIds());

        $this->patch(route('users.update', $casey), ['roles' => ['sales', 'rental_desk'], 'location_ids' => [$waterdown->id, $stoney->id]])
            ->assertSessionHasNoErrors();
        $casey->refresh();
        $this->assertSame([UserRole::Sales, UserRole::RentalDesk], $casey->roleList());
        $this->assertCount(2, $casey->yardIds() ?? []);

        $change = AuditEvent::query()->where('subject_type', 'user')->where('subject_id', $casey->id)->where('event', 'updated')->oldest('id')->firstOrFail();
        $this->assertSame(['roles' => ['Sales, Dispatcher', 'Sales, Rental desk']], $change->changes);
    }

    public function test_only_assignable_roles_can_be_given(): void
    {
        $this->post(route('users.store'), ['name' => 'A', 'email' => 'a@example.com', 'roles' => ['owner']])->assertSessionHasErrors('roles.0');
        $this->post(route('users.store'), ['name' => 'B', 'email' => 'b@example.com', 'roles' => []])->assertSessionHasErrors('roles');
        $this->post(route('users.store'), ['name' => 'C', 'email' => $this->owner->email, 'roles' => ['sales']])->assertSessionHasErrors('email');

        $sales = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Sales]);
        $this->patch(route('users.update', $sales), ['roles' => ['owner']])->assertSessionHasErrors('roles.0');
        $this->assertSame(UserRole::Sales, $sales->refresh()->role);
    }

    public function test_owners_change_roles_and_remove_users(): void
    {
        Notification::fake();

        $sales = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Sales, 'name' => 'Sam Sales']);

        $this->patch(route('users.update', $sales), ['roles' => ['admin']])->assertSessionHasNoErrors();
        $this->assertSame(UserRole::Admin, $sales->refresh()->role);

        $change = AuditEvent::query()->where('subject_type', 'user')->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(['roles' => ['Sales', 'Admin']], $change->changes);

        $this->post(route('users.resend', $sales))->assertRedirect();
        Notification::assertSentTo($sales, UserInviteMessage::class);

        $this->delete(route('users.destroy', $sales))->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $sales->id]);
        $this->assertTrue(AuditEvent::query()->where('subject_type', 'user')->where('subject_id', $sales->id)->where('event', 'deleted')->exists());
    }

    public function test_permissions_are_granted_and_removed_per_person(): void
    {
        $sales = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Sales]);
        $this->assertFalse($sales->hasPermission(Permission::SetManualPrices));

        $this->patch(route('users.update', $sales), ['permissions' => [
            'set_manual_prices' => true,
            'see_costs' => false,
        ]])->assertSessionHasNoErrors();

        $sales->refresh();
        $this->assertTrue($sales->hasPermission(Permission::SetManualPrices));
        $this->assertFalse($sales->hasPermission(Permission::SeeCosts));
        $this->assertSame(['set_manual_prices' => true, 'see_costs' => false], $sales->permissions);

        $logged = AuditEvent::query()->where('subject_id', $sales->id)->where('event', 'permissions changed')->firstOrFail();
        $this->assertSame(['from roles', 'granted'], $logged->changes['Set manual line prices']);

        // Back to following their roles.
        $this->patch(route('users.update', $sales), ['permissions' => ['set_manual_prices' => null, 'see_costs' => null]])->assertSessionHasNoErrors();
        $this->assertNull($sales->refresh()->permissions);
        $this->assertFalse($sales->hasPermission(Permission::SetManualPrices));
    }

    public function test_the_owner_drivers_and_other_businesses_are_off_limits(): void
    {
        // Not yourself (the owner).
        $this->patch(route('users.update', $this->owner), ['roles' => ['sales']])->assertNotFound();
        $this->delete(route('users.destroy', $this->owner))->assertNotFound();

        // Not people who only drive: they're managed under Settings → Drivers, and not listed here.
        $driver = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Driver]);
        $this->patch(route('users.update', $driver), ['roles' => ['sales']])->assertNotFound();
        $this->delete(route('users.destroy', $driver))->assertNotFound();
        $this->get(route('users.index'))->assertInertia(fn (Assert $page) => $page->has('users', 1));

        // Not another business's people.
        $other = Tenant::factory()->create();
        $stranger = User::factory()->create(['tenant_id' => $other->id, 'role' => UserRole::Sales]);
        $this->patch(route('users.update', $stranger), ['roles' => ['admin']])->assertNotFound();
        $this->delete(route('users.destroy', $stranger))->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $stranger->id, 'role' => 'sales']);
    }

    public function test_admins_manage_users_but_not_the_profit_report(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Admin]);
        $sales = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Sales]);
        $this->actingAs($admin);

        $this->get(route('users.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('isOwner', false));
        $this->post(route('users.store'), ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['admin']])->assertSessionHasNoErrors();

        // Only the owner hands out the P&L.
        $this->patch(route('users.update', $sales), ['permissions' => ['see_profit' => true]])->assertForbidden();
        $this->put(route('users.reports'), ['admins_can_view_profit' => true])->assertForbidden();
        $this->assertFalse($sales->refresh()->hasPermission(Permission::SeeProfit));

        // Admins can't manage the owner.
        $this->patch(route('users.update', $this->owner), ['roles' => ['sales']])->assertNotFound();
    }

    public function test_sales_and_other_roles_dont_manage_users(): void
    {
        foreach ([UserRole::Sales, UserRole::Accounts, UserRole::YardCrew] as $role) {
            $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role]);
            $this->actingAs($user);

            $this->post(route('users.store'), ['name' => 'X', 'email' => "x-{$role->value}@example.com", 'roles' => ['admin']])->assertForbidden();
        }
    }

    public function test_a_person_can_be_given_manage_users(): void
    {
        $sales = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Sales]);
        $this->patch(route('users.update', $sales), ['permissions' => ['manage_users' => true]])->assertSessionHasNoErrors();

        $this->actingAs($sales->refresh());
        $this->get(route('users.index'))->assertOk();
    }

    public function test_time_and_attendance_only_people_need_no_email(): void
    {
        Notification::fake();

        $this->post(route('users.store'), ['name' => 'Dave', 'roles' => ['sales']])->assertSessionHasErrors('email');
        $this->post(route('users.store'), ['name' => 'Dave', 'roles' => ['time_only', 'yard_crew'], 'email' => 'dave@example.com'])->assertSessionHasErrors('roles');

        $this->post(route('users.store'), ['name' => 'Dave', 'roles' => ['time_only']])->assertSessionHasNoErrors();
        $dave = User::query()->where('name', 'Dave')->sole();
        $this->assertNull($dave->email);
        $this->assertFalse($dave->canSignIn());
        Notification::assertNothingSent();

        // Giving them more than the time clock takes an email, which invites them.
        $this->patch(route('users.update', $dave), ['roles' => ['yard_crew']])->assertSessionHasErrors('email');
        $this->patch(route('users.update', $dave), ['roles' => ['yard_crew'], 'email' => 'dave@example.com'])->assertSessionHasNoErrors();
        $this->assertSame('dave@example.com', $dave->refresh()->email);
        $this->assertTrue($dave->hasRole(UserRole::YardCrew));
        Notification::assertSentTo($dave, UserInviteMessage::class);

        // Once they have one, it's theirs to change.
        $this->patch(route('users.update', $dave), ['email' => 'other@example.com'])->assertSessionHasErrors('email');
    }
}
