<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Audit;
use App\BackOffice\TenantHealth;
use App\BackOffice\TenantMetrics;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\UserInviteMessage;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenants in the back office: the list, setting one up for a new
 * customer, and the page where staff look after an account.
 */
class TenantController extends Controller
{
    public function index(Request $request, TenantMetrics $metrics): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TenantStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'plan' => ['nullable', 'string', 'max:40'],
            'connection' => ['nullable', Rule::in(['shopify', 'standalone', 'quickbooks', 'errors'])],
        ]);

        $query = Tenant::query()
            ->when($filters['search'] ?? null, function (Builder $q, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], Str::lower((string) $search)).'%';

                $q->where(fn (Builder $q) => $q
                    ->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(slug) like ?', [$like])
                    ->orWhereIn('id', User::query()->whereRaw('lower(email) like ?', [$like])->whereNotNull('tenant_id')->select('tenant_id'))
                    ->orWhereIn('id', DB::table('integrations')->whereRaw('lower(external_account) like ?', [$like])->select('tenant_id')));
            })
            ->when($filters['plan'] ?? null, fn (Builder $q, $plan) => $q->where('plan', $plan))
            ->when($filters['connection'] ?? null, function (Builder $q, $connection) {
                $integrations = DB::table('integrations')->select('tenant_id');

                match ($connection) {
                    'shopify' => $q->whereIn('id', $integrations->where('provider', IntegrationProvider::Shopify->value)),
                    'standalone' => $q->whereNotIn('id', $integrations->where('provider', IntegrationProvider::Shopify->value)),
                    'quickbooks' => $q->whereIn('id', $integrations->where('provider', IntegrationProvider::QuickBooks->value)),
                    'errors' => $q->where(fn (Builder $q) => $q
                        ->whereIn('id', DB::table('integrations')->where('status', IntegrationStatus::Error->value)->select('tenant_id'))
                        ->orWhereIn('id', DB::table('quickbooks_syncs')->where('status', 'failed')->select('tenant_id'))),
                    default => null,
                };
            });

        // Counts for the status tabs follow the other filters.
        $counts = (clone $query)->toBase()->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');

        $page = $query
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->orderByRaw('last_active_at is null')
            ->orderByDesc('last_active_at')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $ids = $page->getCollection()->modelKeys();
        $now = CarbonImmutable::now();
        $orders = $metrics->orders($now->subDays(30), $now, $ids);
        $prior = $metrics->orders($now->subDays(60), $now->subDays(30), $ids);
        $rentals = $metrics->rentalsBooked($now->subDays(30), $now, $ids);
        $users = $metrics->users($ids);
        $yards = $metrics->yards($ids);
        $integrations = $metrics->integrations($ids);
        $quickBooks = $metrics->quickBooksBacklog($ids);
        $failures = $metrics->webhookFailures($now->subDay(), $ids);

        $page->through(fn (Tenant $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'status' => $t->status->value,
            'plan' => $t->planLabel(),
            'mrr_cents' => $t->status->isBillable() ? $t->monthly_price_cents : null,
            'yards' => $yards[$t->id] ?? 0,
            'users' => $users[$t->id] ?? 0,
            'orders' => $orders[$t->id]['orders'] ?? 0,
            'rentals' => $rentals[$t->id] ?? 0,
            'last_active_at' => $t->last_active_at?->toIso8601String(),
            'connections' => $this->connections($integrations[$t->id] ?? [], $quickBooks[$t->id] ?? null, $t->stripe_account_id),
            'health' => TenantHealth::of($t, $orders[$t->id]['orders'] ?? 0, $prior[$t->id]['orders'] ?? 0, $integrations[$t->id] ?? [], $quickBooks[$t->id] ?? null, $failures[$t->id]['count'] ?? 0),
        ]);

        return Inertia::render('admin/tenants/index', [
            'tenants' => $page,
            'counts' => [
                'all' => (int) $counts->sum(),
                ...collect(TenantStatus::cases())->mapWithKeys(fn (TenantStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all(),
            ],
            'filters' => [
                'status' => $filters['status'] ?? null,
                'search' => $filters['search'] ?? null,
                'plan' => $filters['plan'] ?? null,
                'connection' => $filters['connection'] ?? null,
            ],
            'plans' => $this->plans(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/tenants/create', [
            'plans' => $this->plans(),
            'statuses' => array_values(array_filter(TenantStatus::options(), fn (array $o) => in_array($o['value'], ['trial', 'active'], true))),
            'defaultTrialEnds' => now()->addDays(30)->toDateString(),
        ]);
    }

    /**
     * Sets up a business for a new customer: the tenant, a first yard, and
     * the owner, who's emailed a link to choose a password.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'status' => ['required', Rule::in([TenantStatus::Trial->value, TenantStatus::Active->value])],
            'plan' => ['nullable', Rule::in(array_keys($this->planLabels()))],
            'monthly_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'trial_ends_at' => ['nullable', 'date', 'required_if:status,trial'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        [$tenant, $owner] = DB::transaction(function () use ($data) {
            $tenant = Tenant::query()->create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'timezone' => $data['timezone'],
                'status' => $data['status'],
                'plan' => $data['plan'] ?? null,
                'monthly_price_cents' => $this->cents($data['monthly_price'] ?? null),
                'trial_ends_at' => $data['status'] === TenantStatus::Trial->value ? ($data['trial_ends_at'] ?? null) : null,
            ]);

            $owner = CurrentTenant::run($tenant, function () use ($tenant, $data) {
                Location::query()->create(['name' => 'Main yard', 'code' => 'MAIN', 'is_default' => true]);

                $owner = User::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $data['owner_name'],
                    'email' => $data['owner_email'],
                    'role' => UserRole::Owner,
                    // Replaced when they choose their own from the emailed link.
                    'password' => Hash::make(Str::random(40)),
                ]);

                Audit::record($tenant, 'created', null, 'Set up by Yardline staff');

                return $owner;
            });

            return [$tenant, $owner];
        });

        $token = Password::broker()->createToken($owner);
        $owner->notify(new UserInviteMessage(
            route('password.reset', ['token' => $token, 'email' => $owner->email]),
            $tenant->name,
            UserRole::Owner->label(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tenant->name} is set up. {$owner->name} has been sent a link to choose a password."]);

        return to_route('admin.tenants.show', $tenant);
    }

    public function show(Tenant $tenant, TenantMetrics $metrics): Response
    {
        $now = CarbonImmutable::now();
        $ids = [$tenant->id];

        $orders = $metrics->orders($now->subDays(30), $now, $ids)[$tenant->id] ?? ['orders' => 0, 'sales_cents' => 0, 'shopify' => 0];
        $prior = $metrics->orders($now->subDays(60), $now->subDays(30), $ids)[$tenant->id] ?? ['orders' => 0, 'sales_cents' => 0, 'shopify' => 0];
        $daily = $metrics->dailyOrders($now->subDays(29)->startOfDay(), $now, $ids)[$tenant->id] ?? [];
        $integrations = $metrics->integrations($ids)[$tenant->id] ?? [];
        $quickBooks = $metrics->quickBooksBacklog($ids)[$tenant->id] ?? null;
        $weekly = $metrics->activeUsers($now->subDays(6), $now, $ids)[$tenant->id] ?? 0;
        $usage = $metrics->moduleUsage($now->subDays(30), $now, $ids)[$tenant->id] ?? [];

        $days = [];

        for ($i = 29; $i >= 0; $i--) {
            $day = $now->subDays($i);
            $days[] = [
                'date' => $day->toDateString(),
                'shopify' => $daily[$day->toDateString()]['shopify'] ?? 0,
                'yardline' => $daily[$day->toDateString()]['other'] ?? 0,
            ];
        }

        $users = User::query()
            ->where('tenant_id', $tenant->id)
            ->orderByRaw('case role when ? then 0 when ? then 1 when ? then 2 else 3 end', [UserRole::Owner->value, UserRole::Admin->value, UserRole::Staff->value])
            ->orderBy('name')
            ->get();

        $locationOrders = DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->where('type', 'sale')
            ->whereIn('status', ['confirmed', 'fulfilled'])
            ->where('confirmed_at', '>=', $now->subDays(30))
            ->groupBy('location_id')
            ->selectRaw('location_id, count(*) as n')
            ->pluck('n', 'location_id');

        $webhooks = WebhookDelivery::query()
            ->where('tenant_id', $tenant->id)
            ->where('received_at', '>=', $now->subDays(7))
            ->toBase()
            ->groupBy('outcome')
            ->selectRaw('outcome, count(*) as n')
            ->pluck('n', 'outcome');

        return Inertia::render('admin/tenants/show', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status->value,
                'plan' => $tenant->plan,
                'plan_label' => $tenant->planLabel(),
                'monthly_price_cents' => $tenant->monthly_price_cents,
                'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
                'currency' => $tenant->currency,
                'timezone' => $tenant->timezone,
                'created_at' => $tenant->created_at?->toIso8601String(),
                'last_active_at' => $tenant->last_active_at?->toIso8601String(),
                'status_changed_at' => $tenant->status_changed_at?->toIso8601String(),
                'staff_notes' => $tenant->staff_notes,
                'modules' => collect($this->moduleLabels())->map(fn (string $label, string $key) => [
                    'key' => $key,
                    'label' => $label,
                    'on' => $tenant->hasModule($key),
                ])->values(),
                'owner' => $users->first(fn (User $u) => $u->role === UserRole::Owner)?->only('id', 'name', 'email'),
                'health' => TenantHealth::of($tenant, $orders['orders'], $prior['orders'], $integrations, $quickBooks, (int) ($webhooks[WebhookDelivery::FAILED] ?? 0)),
            ],
            'stats' => [
                'orders' => $orders['orders'],
                'prior_orders' => $prior['orders'],
                'sales_cents' => $orders['sales_cents'],
                'rentals' => $metrics->rentalsBooked($now->subDays(30), $now, $ids)[$tenant->id] ?? 0,
                'rentals_out' => $metrics->rentalsOut($ids)[$tenant->id] ?? 0,
                'deliveries' => DB::table('stops')->where('tenant_id', $tenant->id)->where('status', 'done')->where('completed_at', '>=', $now->subDays(30))->count(),
                'stock_movements' => $usage['inventory'] ?? 0,
                'weekly_users' => $weekly,
                'users' => $users->count(),
            ],
            'days' => $days,
            'integrations' => $this->integrationCards($tenant, $integrations, $quickBooks, $webhooks->all()),
            'yards' => Location::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (Location $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'code' => $l->code,
                    'is_default' => (bool) $l->is_default,
                    'orders' => (int) ($locationOrders[$l->id] ?? 0),
                ]),
            'users' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->label(),
                'two_factor' => $u->two_factor_confirmed_at !== null,
                'joined' => $u->email_verified_at !== null,
                'last_seen_at' => $u->last_seen_at?->toIso8601String(),
            ]),
            'audit' => AuditEvent::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->with(['user:id,name,is_platform_staff,tenant_id'])
                ->latest('id')
                ->limit(12)
                ->get()
                ->map(fn (AuditEvent $e) => [
                    'id' => $e->id,
                    'at' => $e->created_at->toIso8601String(),
                    'event' => $e->event,
                    'subject' => Str::headline($e->subject_type).($e->subject_label !== null ? " {$e->subject_label}" : ''),
                    'note' => $e->note,
                    'who' => $e->user === null ? 'System' : $e->user->name.($e->user->isPlatformStaff() ? ' (Yardline staff)' : ''),
                ]),
            'options' => [
                'statuses' => TenantStatus::options(),
                'plans' => $this->plans(),
            ],
        ]);
    }

    /**
     * Staff record the plan, price, status and notes, and switch modules.
     * Each change is written to the tenant's audit log.
     */
    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::enum(TenantStatus::class)],
            'plan' => ['sometimes', 'nullable', Rule::in(array_keys($this->planLabels()))],
            'monthly_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'staff_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['boolean'],
        ]);

        if (array_key_exists('modules', $data)) {
            $requested = is_array($data['modules']) ? $data['modules'] : [];
            $data['modules'] = collect(array_keys($this->moduleLabels()))->mapWithKeys(fn (string $key) => [
                $key => (bool) ($requested[$key] ?? $tenant->hasModule($key)),
            ])->all();
        }

        if (array_key_exists('monthly_price', $data)) {
            $data['monthly_price_cents'] = $this->cents($data['monthly_price']);
            unset($data['monthly_price']);
        }

        $tenant->fill($data);

        if (! $tenant->isDirty()) {
            return back();
        }

        $changes = [];

        foreach (array_keys($tenant->getDirty()) as $field) {
            $changes[$field] = [$this->auditValue($tenant->getOriginal($field)), $this->auditValue($tenant->getAttribute($field))];
        }

        // Notes are private to staff: say they changed, not what they say.
        if (isset($changes['staff_notes'])) {
            $changes['staff_notes'] = ['…', '…'];
        }

        $tenant->save();

        CurrentTenant::run($tenant, fn () => Audit::record($tenant, 'updated', $changes, 'Changed by Yardline staff'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved {$tenant->name}."]);

        return back();
    }

    /**
     * Emails someone at a tenant a link to choose a new password.
     */
    public function sendPasswordLink(Tenant $tenant, User $user): RedirectResponse
    {
        abort_unless($user->tenant_id === $tenant->id, 404);

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        Inertia::flash('toast', $status === Password::RESET_LINK_SENT
            ? ['type' => 'success', 'message' => "Sent {$user->name} a link to choose a new password."]
            : ['type' => 'error', 'message' => __($status)]);

        return back();
    }

    /**
     * @param  array<string, array{status: string, account: string|null, last_synced_at: string|null, last_error: string|null, updated_at: string|null}>  $integrations
     * @param  array{failed: int, waiting: int, oldest_failure: string|null}|null  $quickBooks
     * @return list<array{code: string, label: string, ok: bool}>
     */
    private function connections(array $integrations, ?array $quickBooks, ?string $stripeAccount): array
    {
        $out = [];

        if (isset($integrations['shopify'])) {
            $out[] = ['code' => 'SH', 'label' => 'Shopify', 'ok' => $integrations['shopify']['status'] !== IntegrationStatus::Error->value];
        }

        if ($stripeAccount !== null) {
            $out[] = ['code' => 'ST', 'label' => 'Stripe', 'ok' => true];
        }

        if (isset($integrations['quickbooks'])) {
            $out[] = ['code' => 'QB', 'label' => 'QuickBooks Online', 'ok' => $integrations['quickbooks']['status'] !== IntegrationStatus::Error->value && ($quickBooks['failed'] ?? 0) === 0];
        }

        return $out;
    }

    /**
     * @param  array<string, array{status: string, account: string|null, last_synced_at: string|null, last_error: string|null, updated_at: string|null}>  $integrations
     * @param  array{failed: int, waiting: int, oldest_failure: string|null}|null  $quickBooks
     * @param  array<array-key, mixed>  $webhooks  outcome => count, last 7 days
     * @return list<array{name: string, state: string, tone: string, lines: list<string>}>
     */
    private function integrationCards(Tenant $tenant, array $integrations, ?array $quickBooks, array $webhooks): array
    {
        $cards = [];
        $state = fn (string $status): array => match ($status) {
            IntegrationStatus::Connected->value => ['Connected', 'success'],
            IntegrationStatus::Paused->value => ['Paused', 'neutral'],
            default => ['Error', 'danger'],
        };
        $synced = fn (?string $at): string => $at === null ? 'Not synced yet' : 'Last synced '.CarbonImmutable::parse($at)->diffForHumans();

        $shopify = $integrations['shopify'] ?? null;
        $received = (int) ($webhooks[WebhookDelivery::ACCEPTED] ?? 0) + (int) ($webhooks[WebhookDelivery::IGNORED] ?? 0);
        $failed = (int) ($webhooks[WebhookDelivery::FAILED] ?? 0);

        if ($shopify === null) {
            $cards[] = ['name' => 'Shopify', 'state' => 'Not connected', 'tone' => 'neutral', 'lines' => ['Running on Yardline’s own orders and checkout.']];
        } else {
            [$label, $tone] = $state($shopify['status']);
            $cards[] = ['name' => 'Shopify', 'state' => $label, 'tone' => $tone, 'lines' => array_values(array_filter([
                $shopify['account'],
                $synced($shopify['last_synced_at']),
                "{$received} webhooks received, {$failed} failed (7 days)",
                $shopify['last_error'],
            ]))];
        }

        $cards[] = $tenant->stripe_account_id === null
            ? ['name' => 'Stripe', 'state' => 'Not connected', 'tone' => 'neutral', 'lines' => ['No Stripe account linked for card payments.']]
            : ['name' => 'Stripe', 'state' => 'Connected', 'tone' => 'success', 'lines' => [$tenant->stripe_account_id]];

        $qbo = $integrations['quickbooks'] ?? null;

        if ($qbo === null) {
            $cards[] = ['name' => 'QuickBooks Online', 'state' => 'Not connected', 'tone' => 'neutral', 'lines' => ['Invoices and payments aren’t being sent to their books.']];
        } else {
            [$label, $tone] = $state($qbo['status']);

            if (($quickBooks['failed'] ?? 0) > 0) {
                [$label, $tone] = ['Failing', 'danger'];
            }

            $cards[] = ['name' => 'QuickBooks Online', 'state' => $label, 'tone' => $tone, 'lines' => array_values(array_filter([
                $synced($qbo['last_synced_at']),
                ($quickBooks['failed'] ?? 0) > 0 ? "{$quickBooks['failed']} records failed" : null,
                ($quickBooks['waiting'] ?? 0) > 0 ? "{$quickBooks['waiting']} waiting to send" : null,
                $qbo['last_error'],
            ]))];
        }

        return $cards;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function plans(): array
    {
        return collect($this->planLabels())->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }

    /**
     * @return array<string, string>
     */
    private function planLabels(): array
    {
        /** @var array<string, string> $plans */
        $plans = config('yardline.back_office.plans', []);

        return $plans;
    }

    /**
     * @return array<string, string>
     */
    private function moduleLabels(): array
    {
        /** @var array<string, string> $modules */
        $modules = config('yardline.back_office.modules', []);

        return $modules;
    }

    private function cents(mixed $amount): ?int
    {
        return $amount === null || $amount === '' ? null : (int) round((float) $amount * 100);
    }

    private function auditValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof TenantStatus => $value->value,
            $value instanceof CarbonImmutable => $value->toDateString(),
            default => $value,
        };
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'yard';
        $slug = $base;

        for ($i = 2; Tenant::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
