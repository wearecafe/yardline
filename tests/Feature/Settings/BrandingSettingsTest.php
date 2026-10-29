<?php

namespace Tests\Feature\Settings;

use App\Email\BrandSettings;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\BrandingTestMessage;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function valid(array $overrides = []): array
    {
        return [
            'primary' => '#1F5C99',
            'header_style' => 'light',
            'footer_note' => 'Family owned since 1987',
            'tax_numbers' => 'HST 81234 5678 RT0001',
            ...$overrides,
        ];
    }

    public function test_owners_set_the_primary_colour_footer_and_tax_numbers(): void
    {
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();

        $this->get(route('branding.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/branding')
                ->where('brand.colour_system', false)
                ->has('tokens', 12));

        $this->put(route('branding.update'), $this->valid(['primary' => '1f5c99']))->assertSessionHasNoErrors();

        $brand = BrandSettings::for($tenant->refresh());
        $this->assertSame('#1F5C99', $brand->primary);
        $this->assertSame('Family owned since 1987', $brand->footerNote);
        $this->assertSame('HST 81234 5678 RT0001', $brand->taxNumbers);
    }

    public function test_single_colours_are_ignored_until_staff_switch_the_colour_system_on(): void
    {
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();

        $this->put(route('branding.update'), $this->valid(['overrides' => ['primary_subtle' => '#ABCDEF']]))->assertSessionHasNoErrors();
        $this->assertSame([], BrandSettings::for($tenant->refresh())->overrides);

        BrandSettings::save($tenant, ['colour_system' => true]);

        $this->put(route('branding.update'), $this->valid(['overrides' => ['primary_subtle' => '#ABCDEF', 'bogus' => '#000000']]))->assertSessionHasNoErrors();
        $this->assertSame(['primary_subtle' => '#ABCDEF'], BrandSettings::for($tenant->refresh())->overrides);

        // Colours that are hard to read together can't be saved.
        $this->put(route('branding.update'), $this->valid(['overrides' => ['ink' => '#EEEEEE']]))->assertSessionHasErrors('overrides');
    }

    public function test_tenants_cant_switch_on_the_colour_system_or_hide_the_footer_themselves(): void
    {
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();

        $this->put(route('branding.update'), $this->valid(['colour_system' => true, 'sent_with_yardrunner' => false]))->assertSessionHasNoErrors();

        $brand = BrandSettings::for($tenant->refresh());
        $this->assertFalse($brand->colourSystem);
        $this->assertTrue($brand->sentWithYardRunner);
    }

    public function test_the_preview_draws_unsaved_changes(): void
    {
        $this->signInToNewTenant();

        $this->get(route('branding.preview', ['primary' => '#2F6B3B', 'footer_note' => 'Unsaved note']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSee('#2F6B3B', false)
            ->assertSee('Unsaved note');
    }

    public function test_a_test_email_goes_to_the_signed_in_user(): void
    {
        Notification::fake();
        $user = $this->signInToNewTenant();

        $this->post(route('branding.test'))->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(BrandingTestMessage::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $user->email);
    }

    public function test_the_logo_is_stored_and_served_publicly(): void
    {
        Storage::fake((string) config('yardrunner.files_disk'));
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();

        $this->post(route('branding.logo.store'), [
            'logo' => UploadedFile::fake()->image('logo.png', 400, 120),
        ])->assertSessionHasNoErrors();

        $brand = BrandSettings::for($tenant->refresh());
        $this->assertNotNull($brand->logoPath);
        Storage::disk((string) config('yardrunner.files_disk'))->assertExists($brand->logoPath);

        auth()->logout();
        $this->get(route('brand.logo', ['tenant' => $tenant->slug, 'v' => $brand->logoVersion]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->signInAgain($tenant->id);
        $this->delete(route('branding.logo.destroy'))->assertRedirect();
        $this->assertNull(BrandSettings::for($tenant->refresh())->logoPath);
    }

    public function test_svg_logos_are_refused(): void
    {
        $this->signInToNewTenant();

        $this->post(route('branding.logo.store'), [
            'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('logo');
    }

    public function test_only_owners_and_admins_change_branding(): void
    {
        $this->signInToNewTenant(UserRole::Sales);

        $this->get(route('branding.edit'))->assertForbidden();
        $this->put(route('branding.update'), $this->valid())->assertForbidden();
    }

    private function signInAgain(int $tenantId): void
    {
        $this->actingAs(User::query()->where('tenant_id', $tenantId)->firstOrFail());
    }
}
