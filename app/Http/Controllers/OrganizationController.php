<?php

namespace App\Http\Controllers;

use App\Credit\CreditCheck;
use App\Enums\PaymentTerms;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceList;
use App\Support\Money;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $organizations = Organization::query()
            ->with('priceList:id,name')
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('account_number', "%{$search}%")))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('organizations/index', [
            'organizations' => $organizations,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('organizations/create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = Organization::query()->create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$organization->name} added."]);

        return to_route('organizations.show', $organization);
    }

    public function show(Organization $organization, CreditCheck $credit): Response
    {
        $organization->load(['priceList:id,name', 'customers']);
        $used = $credit->usedCents($organization);

        return Inertia::render('organizations/show', [
            'organization' => $organization,
            'credit' => [
                'limit_cents' => $organization->credit_limit_cents,
                'used_cents' => $used,
                'available_cents' => max(0, $organization->credit_limit_cents - $used),
            ],
            'orders' => $organization->orders()
                ->with(['organization:id,name', 'customer:id,first_name,last_name'])
                ->latest('id')
                ->limit(25)
                ->get()
                ->map(fn (Order $order) => OrderController::summary($order)),
            'paymentTerms' => PaymentTerms::options(),
        ]);
    }

    public function edit(Organization $organization): Response
    {
        return Inertia::render('organizations/edit', [
            'organization' => $organization,
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $data = $this->validated($request, $organization);

        // Credit settings are an owner/admin decision.
        if (Gate::denies('manage-business')) {
            unset($data['credit_limit_cents'], $data['credit_hold'], $data['payment_terms']);
        }

        $organization->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Organization saved.']);

        return to_route('organizations.show', $organization);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Organization $organization = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:30', TenantRule::unique('organizations', 'account_number', $organization?->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
            'price_list_id' => ['nullable', TenantRule::exists('price_lists')],
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'payment_terms' => ['required', Rule::enum(PaymentTerms::class)],
            'credit_hold' => ['boolean'],
            'tax_exempt' => ['boolean'],
            'tax_exempt_number' => ['nullable', 'required_if_accepted:tax_exempt', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return [
            ...Arr::except($data, ['credit_limit']),
            'country' => strtoupper($data['country']),
            'credit_limit_cents' => Money::parse($data['credit_limit']) ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'priceLists' => PriceList::query()->orderBy('name')->get(['id', 'name']),
            'paymentTerms' => PaymentTerms::options(),
            'canManageCredit' => Gate::allows('manage-business'),
        ];
    }
}
