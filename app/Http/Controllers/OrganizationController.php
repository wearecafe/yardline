<?php

namespace App\Http\Controllers;

use App\Credit\CreditCheck;
use App\Enums\PaymentTerms;
use App\Http\Controllers\Settings\AuditController;
use App\Models\ContractPrice;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLocation;
use App\Models\PriceList;
use App\Models\Product;
use App\Receivables\PalletLedger;
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
        $organization->load(['priceList:id,name', 'customers', 'contacts', 'locations.yard:id,name', 'contractPrices.product:id,sku,name', 'contractPrices.unit:id,name']);
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
            'history' => AuditController::historyFor($organization),
            'account' => [
                'pallets_out' => app(PalletLedger::class)->outstanding($organization),
                'credit_cents' => (int) CreditNote::query()->where('organization_id', $organization->id)->where('status', 'open')->get()->sum(fn ($c) => $c->remainingCents()),
                'overdue_cents' => (int) Invoice::query()->with('order')->where('organization_id', $organization->id)->where('status', 'open')->whereDate('due_on', '<', now()->toDateString())->get()->sum(fn ($i) => $i->balanceCents()),
            ],
            'contacts' => $organization->contacts->sortBy('first_name')->values()->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'role' => $customer->getRelation('membership')->role,
                'can_charge_account' => (bool) $customer->getRelation('membership')->can_charge_account,
                'primary' => $customer->organization_id === $organization->id,
            ]),
            'roles' => collect(CustomerMembership::ROLES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'sites' => $organization->locations->map(fn (OrganizationLocation $site) => [
                ...$site->only('id', 'name', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'delivery_notes', 'location_id', 'po_required', 'is_default', 'is_active'),
                'yard' => $site->yard?->name,
            ]),
            'yards' => Location::query()->orderBy('name')->get(['id', 'name']),
            'contractPrices' => $organization->contractPrices->sortByDesc('id')->values()->map(fn (ContractPrice $price) => [
                'id' => $price->id,
                'product' => $price->product->name,
                'sku' => $price->product->sku,
                'unit' => $price->unit?->name,
                'price_cents' => $price->price_cents,
                'starts_on' => $price->starts_on?->toDateString(),
                'ends_on' => $price->ends_on?->toDateString(),
                'in_effect' => $price->inEffect(),
                'note' => $price->note,
            ]),
            'products' => Product::query()->with('units:id,product_id,name,is_base')->where('kind', 'sale')->where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'label' => "{$product->name} · {$product->sku}",
                    'units' => $product->units->map(fn ($unit) => ['id' => $unit->id, 'name' => $unit->name, 'is_base' => $unit->is_base])->values(),
                ]),
            'otherCustomers' => Customer::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'organization_id'])
                ->reject(fn (Customer $customer) => $organization->contacts->contains('id', $customer->id))
                ->values()
                ->map(fn (Customer $customer) => ['id' => $customer->id, 'name' => $customer->full_name]),
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

        // Credit settings: owner, admin and accounts.
        if (Gate::denies('manage-credit')) {
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
            'canManageCredit' => Gate::allows('manage-credit'),
        ];
    }
}
