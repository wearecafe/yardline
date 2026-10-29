<?php

namespace App\Http\Controllers;

use App\Access\UserAccounts;
use App\Audit\Audit;
use App\Billing\AddOns;
use App\Billing\Plan;
use App\Email\BrandSettings;
use App\Email\ColourSystem;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Onboarding\Setup;
use App\Payments\Connect\AccountStatus;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use Closure;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup: what a new business fills in after signing up. The business and
 * its yards are required; everything after can be skipped and stays on the
 * checklist on Today. See App\Onboarding\Setup and docs/scope/tenant-setup.md.
 */
class SetupController extends Controller
{
    /** Provinces and territories, for the yard address. */
    public const PROVINCES = [
        'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories',
        'NU' => 'Nunavut', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec',
        'SK' => 'Saskatchewan', 'YT' => 'Yukon',
    ];

    /** Roles Setup can invite. Drivers need trucks, and time-only people a PIN, first. */
    private const INVITABLE = [UserRole::Admin, UserRole::Sales, UserRole::RentalDesk, UserRole::YardCrew, UserRole::Dispatcher, UserRole::Purchasing, UserRole::Accounts, UserRole::ServiceTech];

    /** Starting points for the brand colour. */
    private const SWATCHES = [ColourSystem::DEFAULT_PRIMARY, '#C2410C', '#B91C1C', '#A16207', '#15803D', '#1D4ED8', '#7C3AED'];

    public function index(): RedirectResponse
    {
        return to_route('setup.step', $this->setup()->resumeStep());
    }

    public function show(Request $request, string $step): Response|RedirectResponse
    {
        $setup = $this->setup();

        if (! $setup->canOpen($step)) {
            return to_route('setup.step', $setup->resumeStep());
        }

        if ($step !== 'done') {
            $setup->remember($step);
        }

        return Inertia::render("setup/{$step}", [
            'setup' => [
                'step' => $step,
                'title' => Setup::STEPS[$step],
                'required' => in_array($step, Setup::REQUIRED, true),
                'previous' => $setup->previous($step),
                'next' => $setup->next($step),
                'steps' => $setup->steps(),
                'basics_done' => $setup->basicsDone(),
            ],
            ...$this->props($step, $setup, $request),
        ]);
    }

    /**
     * Step 1: the business's name, time zone, reply-to address and tax
     * numbers.
     */
    public function business(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'reply_to_email' => ['required', 'email', 'max:255'],
            'tax_numbers' => ['nullable', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($tenant, $data) {
            $settings = $tenant->settings ?? [];
            $settings['reply_to_email'] = $data['reply_to_email'];
            $tenant->update(['name' => $data['name'], 'timezone' => $data['timezone'], 'settings' => $settings]);

            $taxNumbers = trim((string) ($data['tax_numbers'] ?? ''));

            if ($taxNumbers !== BrandSettings::for($tenant)->taxNumbers) {
                BrandSettings::save($tenant, ['tax_numbers' => $taxNumbers]);
            }

            Setup::for($tenant)->complete('business');
        });

        return to_route('setup.step', 'yards');
    }

    /**
     * Step 2: the yards, with full addresses. Saving them opens YardRunner.
     */
    public function yards(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $setup = Setup::for($tenant);

        if (! $setup->isDone('business')) {
            return to_route('setup.step', 'business');
        }

        $input = (array) $request->input('yards', []);

        // The row a "yards.N.field" attribute belongs to.
        $row = function (string $attribute) use ($input): array {
            $yard = $input[(int) explode('.', $attribute)[1]] ?? [];

            return is_array($yard) ? $yard : [];
        };

        $data = $request->validate([
            'yards' => ['required', 'array', 'min:1', 'max:20'],
            'yards.*.id' => ['nullable', 'integer', TenantRule::exists('locations')],
            'yards.*.name' => ['required', 'string', 'max:255'],
            'yards.*.code' => [
                'required',
                'string',
                'max:20',
                'alpha_dash',
                'distinct:ignore_case',
                // Unique in the business, the yard's own row aside.
                function (string $attribute, mixed $value, Closure $fail) use ($row) {
                    $id = $row($attribute)['id'] ?? null;
                    $taken = Location::query()
                        ->where('code', strtoupper((string) $value))
                        ->when(is_numeric($id), fn ($q) => $q->whereKeyNot((int) $id))
                        ->exists();

                    if ($taken) {
                        $fail('Another yard already uses this code.');
                    }
                },
            ],
            'yards.*.address_line1' => ['required', 'string', 'max:255'],
            'yards.*.address_line2' => ['nullable', 'string', 'max:255'],
            'yards.*.city' => ['required', 'string', 'max:255'],
            'yards.*.country' => ['required', Rule::in(['CA', 'US'])],
            'yards.*.region' => [
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail) use ($row) {
                    if (($row($attribute)['country'] ?? null) === 'CA' && ! isset(self::PROVINCES[strtoupper((string) $value)])) {
                        $fail('Choose a province.');
                    }
                },
            ],
            'yards.*.postal_code' => [
                'required',
                'string',
                'max:20',
                function (string $attribute, mixed $value, Closure $fail) use ($row) {
                    if (($row($attribute)['country'] ?? null) === 'CA' && ! preg_match('/^[A-Za-z]\d[A-Za-z] ?\d[A-Za-z]\d$/', trim((string) $value))) {
                        $fail('Enter a postal code like L7L 4X7.');
                    }
                },
            ],
        ], [
            'yards.*.code.distinct' => 'Each yard needs its own code.',
            'yards.*.address_line1.required' => 'Enter the yard’s street address. Pickup orders are taxed here.',
        ]);

        DB::transaction(function () use ($data, $setup) {
            $hasDefault = Location::query()->where('is_default', true)->exists();

            foreach ($data['yards'] as $yard) {
                $location = isset($yard['id']) ? Location::query()->findOrFail((int) $yard['id']) : new Location;
                $location->fill([
                    'name' => $yard['name'],
                    'code' => strtoupper($yard['code']),
                    'address_line1' => $yard['address_line1'],
                    'address_line2' => $yard['address_line2'] ?? null,
                    'city' => $yard['city'],
                    'region' => strtoupper($yard['region']),
                    'postal_code' => $this->postalCode($yard['postal_code'], $yard['country']),
                    'country' => $yard['country'],
                ]);

                if (! $hasDefault) {
                    $location->is_default = true;
                    $hasDefault = true;
                }

                $location->save();
            }

            $setup->complete('yards');
            $setup->openUp();
        });

        return to_route('setup.step', 'work');
    }

    /**
     * Step 3: what the yard does. Rentals and time tracking switch their
     * add-ons on (free in the trial); the rest shapes the checklist.
     */
    public function work(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();

        $data = $request->validate([
            'delivers' => ['required', 'boolean'],
            'contractors' => ['required', 'boolean'],
            'rentals' => ['required', 'boolean'],
            'time' => ['required', 'boolean'],
        ]);

        $setup = Setup::for($tenant);
        $setup->saveAnswers(['delivers' => (bool) $data['delivers'], 'contractors' => (bool) $data['contractors']]);

        if (! Demo::isCurrentTenant()) {
            AddOns::setRentals($tenant, (bool) $data['rentals']);
            AddOns::setTime($tenant, (bool) $data['time']);
        }

        Setup::for($tenant->refresh())->complete('work');

        return to_route('setup.step', 'products');
    }

    /**
     * Step 5: invite one person. They're emailed a link to choose a
     * password, and work every yard until someone changes that.
     */
    public function invite(Request $request): RedirectResponse
    {
        abort_unless(Gate::allows('manage-users'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(array_map(fn (UserRole $r) => $r->value, self::INVITABLE))],
        ], ['email.unique' => 'Someone with this email already uses YardRunner.']);

        $user = UserAccounts::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'roles' => [$data['role']],
        ]);

        Setup::for($this->tenant())->complete('team');

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent {$user->name} a link to choose a password."]);

        return back();
    }

    /**
     * Step 6: the brand colour. The logo goes through Settings → Branding's
     * upload, which comes back here.
     */
    public function brand(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();

        $data = $request->validate([
            'primary' => ['required', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
        ], ['primary.regex' => 'Enter a colour like #C2410C.']);

        $before = BrandSettings::for($tenant)->primary;
        $primary = (string) ColourSystem::normalize($data['primary']);

        if ($primary !== $before) {
            BrandSettings::save($tenant, ['primary' => $primary, 'generator_version' => ColourSystem::VERSION]);
            Audit::record($tenant, 'branding.updated', ['primary' => [$before, $primary]]);
        }

        Setup::for($tenant)->complete('brand');

        return to_route('setup.step', 'payments');
    }

    /** Move past a step, done or skipped. */
    public function next(string $step): RedirectResponse
    {
        $setup = $this->setup();
        abort_unless($setup->canOpen($step) && ! in_array($step, Setup::REQUIRED, true), 404);

        $setup->visit($step);

        return to_route('setup.step', $setup->next($step));
    }

    /** Leave Setup for Today. The checklist picks up what's left. */
    public function finish(): RedirectResponse
    {
        $setup = $this->setup();
        abort_unless($setup->basicsDone(), 404);

        $setup->finish();

        return to_route('dashboard');
    }

    public function hideChecklist(): RedirectResponse
    {
        $this->setup()->hideChecklist();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Checklist hidden. The help center has the full setup guide.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function props(string $step, Setup $setup, Request $request): array
    {
        $tenant = $this->tenant();

        return match ($step) {
            'business' => [
                'business' => [
                    'name' => $tenant->name,
                    'timezone' => $tenant->timezone,
                    'reply_to_email' => $tenant->replyToEmail() ?? $request->user()?->email,
                    'tax_numbers' => BrandSettings::for($tenant)->taxNumbers,
                ],
                // A guess from the browser until they've saved this step.
                'guessTimezone' => ! $setup->isDone('business'),
                'timezones' => array_values(array_filter(
                    DateTimeZone::listIdentifiers(DateTimeZone::AMERICA),
                    fn (string $zone) => str_starts_with($zone, 'America/'),
                )),
            ],
            'yards' => [
                'yards' => Location::query()->orderByDesc('is_default')->orderBy('id')->get()->map(fn (Location $l) => [
                    ...$l->only('id', 'name', 'code', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country'),
                ]),
                'provinces' => collect(self::PROVINCES)->map(fn (string $label, string $value) => compact('value', 'label'))->values(),
            ],
            'work' => [
                'answers' => [
                    'delivers' => $setup->isDone('work') ? $setup->answer('delivers') : true,
                    'contractors' => $setup->isDone('work') ? $setup->answer('contractors') : true,
                    'rentals' => $tenant->rentals_enabled,
                    'time' => $tenant->hasModule('time'),
                ],
                'prices' => [
                    'rentals_cents' => Plan::amount(Plan::RENTALS),
                    'time_cents' => Plan::amount(Plan::TIME),
                    'trial_days' => (int) config('yardrunner.billing.trial_days', 14),
                ],
            ],
            'products' => [
                'productCount' => Product::query()->count(),
                'supportEmail' => config('yardrunner.support_email'),
            ],
            'team' => [
                'people' => User::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereKeyNot($request->user()?->id)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => UserAccounts::roleLabels($user),
                    ]),
                'roles' => array_map(fn (UserRole $r) => ['value' => $r->value, 'label' => $r->label(), 'description' => $r->description()], self::INVITABLE),
                'canInvite' => Gate::allows('manage-users'),
            ],
            'brand' => (function () use ($tenant) {
                $brand = BrandSettings::for($tenant);

                return [
                    'brand' => [
                        'name' => $tenant->name,
                        'primary' => $brand->primary,
                        'logo_url' => $brand->logoPath !== null ? route('brand.logo', ['tenant' => $tenant->slug, 'v' => $brand->logoVersion]) : null,
                    ],
                    'swatches' => self::SWATCHES,
                    'logoMaxKb' => (int) config('yardrunner.email.logo_max_kb'),
                ];
            })(),
            'payments' => [
                'status' => AccountStatus::for($tenant)->toArray(),
                'demo' => Demo::isCurrentTenant(),
            ],
            'done' => [
                'checklist' => $setup->checklistItems(),
            ],
            default => [],
        };
    }

    private function postalCode(string $code, string $country): string
    {
        $code = strtoupper(trim($code));

        if ($country === 'CA') {
            $code = str_replace(' ', '', $code);

            return substr($code, 0, 3).' '.substr($code, 3);
        }

        return $code;
    }

    private function setup(): Setup
    {
        return Setup::for($this->tenant());
    }

    private function tenant(): Tenant
    {
        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        return $tenant;
    }
}
