<?php

namespace App\Onboarding;

use App\Email\BrandSettings;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Product;
use App\Models\RentalUnit;
use App\Models\SendingDomain;
use App\Models\Tenant;
use App\Models\Truck;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a new business is with setting up YardRunner: the steps it goes
 * through after signing up, and the checklist on Today afterwards.
 *
 * The business and its yards are required. Until both are saved
 * (`tenants.onboarded_at`), owners and admins are kept in Setup (see
 * EnsureSetUp). The other steps can be skipped; whether they're done is
 * worked out from the business's real data, so doing something outside
 * Setup ticks it off too. Progress is kept in tenants.settings['onboarding'].
 */
final class Setup
{
    /** Both must be saved before the rest of YardRunner opens. */
    public const REQUIRED = ['business', 'yards'];

    /** In order. `done` is the summary at the end. */
    public const STEPS = [
        'business' => 'Your business',
        'yards' => 'Your yards',
        'work' => 'How you work',
        'products' => 'Your products',
        'team' => 'Your team',
        'brand' => 'Logo and colour',
        'payments' => 'Card payments and tax',
        'done' => 'You’re set',
    ];

    /** What the "How you work" step asks that isn't an add-on. */
    public const ANSWERS = ['delivers', 'contractors'];

    private function __construct(private readonly Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** The business and its yards are saved: YardRunner is open. */
    public function basicsDone(): bool
    {
        return $this->tenant->onboarded_at !== null;
    }

    public function finished(): bool
    {
        return $this->state()['finished_at'] !== null;
    }

    /**
     * Whether a step is done: saved for the steps that are forms, worked
     * out from the business's data for the rest.
     */
    public function isDone(string $step): bool
    {
        $saved = $this->state()['completed'];

        return match ($step) {
            'business' => isset($saved['business']),
            'yards' => $this->basicsDone(),
            'work' => isset($saved['work']),
            'products' => $this->count(Product::class) > 0,
            'team' => $this->teamCount() > 0,
            'brand' => BrandSettings::for($this->tenant)->logoPath !== null || isset($saved['brand']),
            'payments' => $this->tenant->acceptsCards(),
            'done' => $this->finished(),
            default => false,
        };
    }

    /**
     * Whether a step can be opened yet: the business comes first, then the
     * yards, then anything.
     */
    public function canOpen(string $step): bool
    {
        if (! isset(self::STEPS[$step])) {
            return false;
        }

        return match ($step) {
            'business' => true,
            'yards' => $this->isDone('business'),
            default => $this->basicsDone(),
        };
    }

    /** The step to pick up from. */
    public function resumeStep(): string
    {
        if (! $this->isDone('business')) {
            return 'business';
        }

        if (! $this->basicsDone()) {
            return 'yards';
        }

        $last = $this->state()['last_step'];

        return is_string($last) && $this->canOpen($last) ? $last : 'work';
    }

    /** The step after this one. */
    public function next(string $step): string
    {
        $keys = array_keys(self::STEPS);
        $index = array_search($step, $keys, true);

        return $index === false ? 'done' : ($keys[$index + 1] ?? 'done');
    }

    public function previous(string $step): ?string
    {
        $keys = array_keys(self::STEPS);
        $index = array_search($step, $keys, true);

        return $index === false || $index === 0 ? null : $keys[$index - 1];
    }

    /**
     * The steps for the progress rail.
     *
     * @return list<array{key: string, title: string, required: bool, status: 'done'|'skipped'|'todo', open: bool}>
     */
    public function steps(): array
    {
        $visited = $this->state()['visited'];
        $steps = [];

        foreach (self::STEPS as $key => $title) {
            if ($key === 'done') {
                continue;
            }

            $steps[] = [
                'key' => $key,
                'title' => $title,
                'required' => in_array($key, self::REQUIRED, true),
                'status' => $this->isDone($key) ? 'done' : (in_array($key, $visited, true) ? 'skipped' : 'todo'),
                'open' => $this->canOpen($key),
            ];
        }

        return $steps;
    }

    public function answer(string $key): bool
    {
        return (bool) ($this->state()['answers'][$key] ?? false);
    }

    /**
     * The "Finish setting up" card on Today: what's left, each linking to
     * where it's done. Null once it's hidden or everything is done.
     *
     * @return array{items: list<array{key: string, title: string, detail: string, href: string, done: bool}>, done: int, total: int}|null
     */
    public function checklist(): ?array
    {
        if (! $this->basicsDone() || $this->state()['checklist_hidden_at'] !== null) {
            return null;
        }

        $items = $this->checklistItems();
        $done = count(array_filter($items, fn (array $item) => $item['done']));

        return $done === count($items) ? null : ['items' => $items, 'done' => $done, 'total' => count($items)];
    }

    /**
     * Everything worth doing after Setup, done or not. What's shown depends
     * on the answers to "How you work" and the add-ons.
     *
     * @return list<array{key: string, title: string, detail: string, href: string, done: bool}>
     */
    public function checklistItems(): array
    {
        $items = [
            $this->item('products', 'Add your products', 'With the units you sell them in: bag, pallet, tonne.', route('products.create'), $this->isDone('products')),
            $this->item('team', 'Invite your team', 'Counter, yard, dispatch and office staff.', route('users.index'), $this->isDone('team')),
            $this->item('logo', 'Add your logo', 'It goes on quotes, invoices and every customer email.', route('branding.edit'), BrandSettings::for($this->tenant)->logoPath !== null),
            $this->item('payments', 'Take card payments', 'Payment links, rental deposit holds and your sales tax registrations.', route('payments.edit'), $this->tenant->acceptsCards()),
            $this->item('domain', 'Send email from your own domain', 'So quotes and invoices come from your own address.', route('sending-domain.edit'), $this->domainVerified()),
        ];

        if ($this->answer('delivers')) {
            $items[] = $this->item('trucks', 'Add your trucks and delivery prices', 'For the dispatch board and delivery fees on orders.', route('delivery-settings.edit'), $this->count(Truck::class) > 0);
        }

        if ($this->answer('contractors')) {
            $items[] = $this->item('accounts', 'Add your contractor accounts', 'With their contacts, terms and credit limits.', route('organizations.create'), $this->count(Organization::class) > 0);
        }

        if ($this->tenant->rentals_enabled) {
            $items[] = $this->item('fleet', 'Add your rental fleet', 'The equipment you rent, with its rates.', route('fleet.units.create'), $this->count(RentalUnit::class) > 0);
        }

        $billing = $this->tenant->billing();

        if (! $billing->exempt && ! $billing->subscribed) {
            $items[] = $this->item('billing', 'Add payment details for YardRunner', 'Your trial carries over. Nothing is charged until it ends.', route('billing.edit'), false);
        }

        return $items;
    }

    // -- Recording progress ---------------------------------------------

    public function complete(string $step): void
    {
        $this->update(function (array $state) use ($step) {
            $state['completed'][$step] = now()->toIso8601String();
            $state['visited'] = array_values(array_unique([...$state['visited'], $step]));

            return $state;
        });
    }

    /** Opened and moved past, done or not. */
    public function visit(string $step): void
    {
        $this->update(function (array $state) use ($step) {
            $state['visited'] = array_values(array_unique([...$state['visited'], $step]));

            return $state;
        });
    }

    public function remember(string $step): void
    {
        if ($this->state()['last_step'] === $step) {
            return;
        }

        $this->update(fn (array $state) => [...$state, 'last_step' => $step]);
    }

    /**
     * @param  array<string, bool>  $answers
     */
    public function saveAnswers(array $answers): void
    {
        $this->update(function (array $state) use ($answers) {
            $state['answers'] = [...$state['answers'], ...array_intersect_key($answers, array_flip(self::ANSWERS))];

            return $state;
        });
    }

    /** The business and its yards are in: open YardRunner. */
    public function openUp(): void
    {
        if ($this->tenant->onboarded_at === null) {
            $this->tenant->forceFill(['onboarded_at' => now()])->save();
        }
    }

    public function finish(): void
    {
        $this->update(fn (array $state) => [...$state, 'finished_at' => $state['finished_at'] ?? now()->toIso8601String()]);
    }

    public function hideChecklist(): void
    {
        $this->update(fn (array $state) => [...$state, 'checklist_hidden_at' => now()->toIso8601String()]);
    }

    /**
     * What the back office shows: when it was set up, or where they are.
     */
    public function summary(): string
    {
        if ($this->basicsDone()) {
            return 'Set up '.$this->tenant->onboarded_at?->format('M j, Y');
        }

        return 'Not set up yet: on '.mb_strtolower(self::STEPS[$this->resumeStep()]);
    }

    // -- Internals -------------------------------------------------------

    /**
     * @return array{completed: array<array-key, string>, visited: list<string>, answers: array<array-key, bool>, last_step: string|null, finished_at: string|null, checklist_hidden_at: string|null}
     */
    private function state(): array
    {
        $raw = $this->tenant->settings['onboarding'] ?? [];
        $raw = is_array($raw) ? $raw : [];

        return [
            'completed' => array_filter((array) ($raw['completed'] ?? []), 'is_string'),
            'visited' => array_values(array_filter((array) ($raw['visited'] ?? []), 'is_string')),
            'answers' => array_map(fn ($v) => (bool) $v, (array) ($raw['answers'] ?? [])),
            'last_step' => is_string($raw['last_step'] ?? null) ? $raw['last_step'] : null,
            'finished_at' => is_string($raw['finished_at'] ?? null) ? $raw['finished_at'] : null,
            'checklist_hidden_at' => is_string($raw['checklist_hidden_at'] ?? null) ? $raw['checklist_hidden_at'] : null,
        ];
    }

    /**
     * @param  callable(array{completed: array<array-key, string>, visited: list<string>, answers: array<array-key, bool>, last_step: string|null, finished_at: string|null, checklist_hidden_at: string|null}): array<string, mixed>  $change
     */
    private function update(callable $change): void
    {
        $settings = $this->tenant->settings ?? [];
        $settings['onboarding'] = $change($this->state());
        $this->tenant->forceFill(['settings' => $settings])->save();
    }

    /**
     * @return array{key: string, title: string, detail: string, href: string, done: bool}
     */
    private function item(string $key, string $title, string $detail, string $href, bool $done): array
    {
        return ['key' => $key, 'title' => $title, 'detail' => $detail, 'href' => $href, 'done' => $done];
    }

    /** People other than the owner, deactivated ones aside. */
    private function teamCount(): int
    {
        return User::query()->where('tenant_id', $this->tenant->id)->where('role', '!=', UserRole::Owner->value)->count();
    }

    private function domainVerified(): bool
    {
        return CurrentTenant::run($this->tenant, fn () => SendingDomain::query()->where('status', SendingDomain::VERIFIED)->exists());
    }

    /**
     * Counts a tenant model's rows for this business, whoever is signed in.
     *
     * @param  class-string<Model>  $model
     */
    private function count(string $model): int
    {
        return CurrentTenant::run($this->tenant, fn () => $model::query()->count());
    }
}
