<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Organization;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $customers = Customer::query()
            ->with('organization:id,name')
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('first_name', "%{$search}%")
                ->orWhereLike('last_name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")
                ->orWhereLike('phone', "%{$search}%")))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('customers/index', [
            'customers' => $customers,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('customers/create', [
            'organizations' => $this->organizations(),
            'organizationId' => $request->integer('organization_id') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = Customer::query()->create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$customer->full_name} added."]);

        return $customer->organization_id
            ? to_route('organizations.show', $customer->organization_id)
            : to_route('customers.index');
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('customers/edit', [
            'customer' => $customer,
            'organizations' => $this->organizations(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Customer saved.']);

        return to_route('customers.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'organization_id' => ['nullable', TenantRule::exists('organizations')],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * @return Collection<int, Organization>
     */
    private function organizations()
    {
        return Organization::query()->orderBy('name')->get(['id', 'name', 'account_number']);
    }
}
