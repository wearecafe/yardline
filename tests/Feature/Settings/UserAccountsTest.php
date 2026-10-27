<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\AuditEvent;
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
 * Settings → Users: the owner adds admins and staff, changes their role
 * and removes them. Drivers stay on their own page.
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

        $this->post(route('users.store'), ['name' => 'Jess Palmer', 'email' => 'jess@example.com', 'role' => 'admin'])
            ->assertSessionHasNoErrors();

        $jess = User::query()->where('email', 'jess@example.com')->firstOrFail();
        $this->assertSame(UserRole::Admin, $jess->role);
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
            ->where('users.1.role', 'admin')
            ->where('users.1.joined', false)
            ->where('users.1.manageable', true)
            ->has('roles', 2));

        $this->assertTrue(AuditEvent::query()->where('subject_type', 'user')->where('subject_id', $jess->id)->where('event', 'created')->exists());

        // Choosing a password from the link verifies their email.
        auth()->logout();
        $token = Password::broker()->createToken($jess);
        $this->post(route('password.update'), ['token' => $token, 'email' => 'jess@example.com', 'password' => 'a-Strong-pass-42!', 'password_confirmation' => 'a-Strong-pass-42!'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($jess->refresh()->email_verified_at);
    }

    public function test_only_admin_and_staff_roles_can_be_given(): void
    {
        $this->post(route('users.store'), ['name' => 'A', 'email' => 'a@example.com', 'role' => 'owner'])->assertSessionHasErrors('role');
        $this->post(route('users.store'), ['name' => 'B', 'email' => 'b@example.com', 'role' => 'driver'])->assertSessionHasErrors('role');
        $this->post(route('users.store'), ['name' => 'C', 'email' => $this->owner->email, 'role' => 'staff'])->assertSessionHasErrors('email');

        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Staff]);
        $this->patch(route('users.update', $staff), ['role' => 'owner'])->assertSessionHasErrors('role');
        $this->assertSame(UserRole::Staff, $staff->refresh()->role);
    }

    public function test_owners_change_roles_and_remove_users(): void
    {
        Notification::fake();

        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Staff, 'name' => 'Sam Staff']);

        $this->patch(route('users.update', $staff), ['role' => 'admin'])->assertSessionHasNoErrors();
        $this->assertSame(UserRole::Admin, $staff->refresh()->role);

        $change = AuditEvent::query()->where('subject_type', 'user')->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(['role' => ['staff', 'admin']], $change->changes);

        $this->post(route('users.resend', $staff))->assertRedirect();
        Notification::assertSentTo($staff, UserInviteMessage::class);

        $this->delete(route('users.destroy', $staff))->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
        $this->assertTrue(AuditEvent::query()->where('subject_type', 'user')->where('subject_id', $staff->id)->where('event', 'deleted')->exists());
    }

    public function test_the_owner_drivers_and_other_businesses_are_off_limits(): void
    {
        // Not yourself (the owner).
        $this->patch(route('users.update', $this->owner), ['role' => 'staff'])->assertNotFound();
        $this->delete(route('users.destroy', $this->owner))->assertNotFound();

        // Not drivers: they're managed under Settings → Drivers, and not listed here.
        $driver = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Driver]);
        $this->patch(route('users.update', $driver), ['role' => 'staff'])->assertNotFound();
        $this->delete(route('users.destroy', $driver))->assertNotFound();
        $this->get(route('users.index'))->assertInertia(fn (Assert $page) => $page->has('users', 1));

        // Not another business's people.
        $other = Tenant::factory()->create();
        $stranger = User::factory()->create(['tenant_id' => $other->id, 'role' => UserRole::Staff]);
        $this->patch(route('users.update', $stranger), ['role' => 'admin'])->assertNotFound();
        $this->delete(route('users.destroy', $stranger))->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $stranger->id, 'role' => 'staff']);
    }

    public function test_only_the_owner_manages_users(): void
    {
        foreach ([UserRole::Admin, UserRole::Staff] as $role) {
            $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role]);
            $this->actingAs($user);

            $this->get(route('users.index'))->assertForbidden();
            $this->post(route('users.store'), ['name' => 'X', 'email' => "x-{$role->value}@example.com", 'role' => 'admin'])->assertForbidden();
        }

        // Admins still manage the rest of the business settings.
        $this->actingAs(User::query()->where('tenant_id', $this->tenant->id)->where('role', UserRole::Admin->value)->firstOrFail());
        $this->get(route('business.edit'))->assertOk();
        $this->get(route('drivers.index'))->assertOk();
    }
}
