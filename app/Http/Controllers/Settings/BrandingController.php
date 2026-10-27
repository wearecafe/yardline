<?php

namespace App\Http\Controllers\Settings;

use App\Audit\Audit;
use App\Email\BrandedMail;
use App\Email\BrandedMessage;
use App\Email\BrandSettings;
use App\Email\ColourSystem;
use App\Email\EmailTheme;
use App\Email\Sender;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Notifications\BrandingTestMessage;
use App\Support\Files;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Branding: how the yard looks on emails to its customers.
 * The full colour system (every token editable) only shows once YardRunner
 * staff switch it on in the back office.
 */
class BrandingController extends Controller
{
    public function edit(): Response
    {
        $tenant = $this->tenant();
        $brand = BrandSettings::for($tenant);

        return Inertia::render('settings/branding', [
            'brand' => [
                'name' => $tenant->name,
                'primary' => $brand->primary,
                'overrides' => (object) $brand->overrides,
                'header_style' => $brand->headerStyle,
                'footer_note' => $brand->footerNote,
                'tax_numbers' => $brand->taxNumbers,
                'logo_url' => $brand->logoPath !== null ? route('brand.logo', ['tenant' => $tenant->slug, 'v' => $brand->logoVersion]) : null,
                'colour_system' => $brand->colourSystem,
                'sent_with_yardrunner' => $brand->sentWithYardRunner,
                'sender' => Sender::forCustomer($tenant)->address,
            ],
            'tokens' => collect(ColourSystem::TOKENS)->map(fn (array $t, string $key) => ['key' => $key, 'label' => $t[0], 'use' => $t[1]])->values(),
            'headerStyles' => collect(BrandSettings::HEADER_STYLES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'previewUrl' => route('branding.preview'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $brand = BrandSettings::for($tenant);

        $data = $request->validate([
            'primary' => ['required', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'header_style' => ['required', Rule::in(array_keys(BrandSettings::HEADER_STYLES))],
            'footer_note' => ['nullable', 'string', 'max:300'],
            'tax_numbers' => ['nullable', 'string', 'max:120'],
            'overrides' => ['nullable', 'array'],
            'overrides.*' => ['nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
        ]);

        $primary = (string) ColourSystem::normalize($data['primary']);
        $overrides = $brand->overrides;

        // Only tenants with the colour system on can change single colours;
        // everyone else's earlier changes stay put but unused.
        if ($brand->colourSystem) {
            $overrides = [];

            foreach ((array) ($data['overrides'] ?? []) as $token => $value) {
                if (isset(ColourSystem::TOKENS[$token]) && is_string($value) && ($hex = ColourSystem::normalize($value)) !== null) {
                    $overrides[(string) $token] = $hex;
                }
            }

            $failing = collect(ColourSystem::checks(ColourSystem::resolve($primary, $overrides)))
                ->filter(fn (array $c) => $c['required'] && ! $c['passes'])
                ->pluck('label');

            if ($failing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'overrides' => 'These colours are too hard to read together: '.$failing->implode(', ').'. Change them or reset them to generated.',
                ]);
            }
        }

        $changes = [
            'primary' => $primary,
            'overrides' => $overrides,
            'header_style' => $data['header_style'],
            'footer_note' => trim((string) ($data['footer_note'] ?? '')),
            'tax_numbers' => trim((string) ($data['tax_numbers'] ?? '')),
            'generator_version' => ColourSystem::VERSION,
        ];

        $before = $brand->toArray();
        BrandSettings::save($tenant, $changes);
        $this->audit($tenant, $before, $changes);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Branding saved. Customer emails use it from now on.']);

        return back();
    }

    public function storeLogo(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();

        $request->validate([
            // SVG and transparent PNGs don't show reliably in email apps.
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:'.(int) config('yardline.email.logo_max_kb'), 'dimensions:min_height=40,max_width=4000,max_height=4000'],
        ]);

        $brand = BrandSettings::for($tenant);
        $path = Files::storeUpload($request->file('logo'), 'branding');

        if ($brand->logoPath !== null) {
            Files::disk()->delete($brand->logoPath);
        }

        BrandSettings::save($tenant, ['logo_path' => $path, 'logo_version' => Str::lower(Str::random(8))]);
        Audit::record($tenant, 'branding.logo_changed');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Logo updated.']);

        return back();
    }

    public function destroyLogo(): RedirectResponse
    {
        $tenant = $this->tenant();
        $brand = BrandSettings::for($tenant);

        if ($brand->logoPath !== null) {
            Files::disk()->delete($brand->logoPath);
            BrandSettings::save($tenant, ['logo_path' => null, 'logo_version' => null]);
            Audit::record($tenant, 'branding.logo_removed');
        }

        return back();
    }

    /**
     * A sample order confirmation in the brand being edited, unsaved values
     * included, for the preview beside the form.
     */
    public function preview(Request $request): HttpResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'primary' => ['nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'header_style' => ['nullable', Rule::in(array_keys(BrandSettings::HEADER_STYLES))],
            'footer_note' => ['nullable', 'string', 'max:300'],
            'tax_numbers' => ['nullable', 'string', 'max:120'],
            'overrides' => ['nullable', 'array'],
            'overrides.*' => ['nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
        ]);

        $draft = $tenant->replicate();
        $draft->id = $tenant->id;
        $draft->slug = $tenant->slug;
        $settings = $tenant->settings ?? [];
        $settings['branding'] = [...BrandSettings::for($tenant)->toArray(), ...array_filter([
            'primary' => $data['primary'] ?? null,
            'header_style' => $data['header_style'] ?? null,
            'footer_note' => $data['footer_note'] ?? null,
            'tax_numbers' => $data['tax_numbers'] ?? null,
        ], fn ($v) => $v !== null)];

        if (BrandSettings::for($tenant)->colourSystem && array_key_exists('overrides', $data)) {
            $settings['branding']['overrides'] = (array) ($data['overrides'] ?? []);
        }

        $draft->settings = $settings;

        $html = $this->sample(new BrandedMessage(EmailTheme::tenant($draft)), $tenant->name)->render();

        return response((string) $html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'; frame-ancestors 'self'");
    }

    /**
     * Sends the sample to the signed-in user, from the address customer
     * emails really use.
     */
    public function test(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $user = $request->user();
        abort_if($user === null || $user->email === null, 422);

        $message = $this->sample(BrandedMail::customer($tenant), $tenant->name);

        Notification::route('mail', $user->email)->notify(new BrandingTestMessage($message));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Test email sent to {$user->email}."]);

        return back();
    }

    private function sample(BrandedMessage $mail, string $business): BrandedMessage
    {
        return $mail
            ->subject("Order 10482 confirmed · {$business}")
            ->title('Your order is confirmed')
            ->greeting('Hi Marco,')
            ->line('Thanks for your order. We’ll deliver to the Maple Ave job site on Thursday, and you’ll get a text when the truck leaves the yard.')
            ->facts([
                'Order' => '10482',
                'PO number' => 'MR-2231',
                'Delivery window' => 'Thu, 7–10 am',
                'Total' => '$2,597.87',
            ])
            ->action('View order', url('/'))
            ->line('Need to change something? Reply to this email or call the yard.');
    }

    private function tenant(): Tenant
    {
        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        return $tenant;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Tenant $tenant, array $before, array $after): void
    {
        $changes = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) != $value && $key !== 'generator_version') {
                $changes[$key] = [is_array($before[$key] ?? null) ? json_encode($before[$key]) : ($before[$key] ?? null), is_array($value) ? json_encode($value) : $value];
            }
        }

        if ($changes !== []) {
            Audit::record($tenant, 'branding.updated', $changes);
        }
    }
}
