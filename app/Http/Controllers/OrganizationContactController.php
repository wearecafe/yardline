<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\Organization;
use App\Orders\OrderRules;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Who buys for an organization, in what role, and whether they can charge
 * to the account. One person can be a contact for several organizations.
 */
class OrganizationContactController extends Controller
{
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', TenantRule::exists('customers')],
            ...$this->rules(),
        ]);

        $customer = Customer::query()->findOrFail((int) $data['customer_id']);

        $organization->contacts()->syncWithoutDetaching([
            $customer->id => [
                'tenant_id' => $organization->tenant_id,
                'role' => $data['role'],
                'can_charge_account' => (bool) ($data['can_charge_account'] ?? false),
            ],
        ]);

        if ($customer->organization_id === null) {
            $customer->update(['organization_id' => $organization->id]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$customer->full_name} added to {$organization->name}."]);

        return back();
    }

    public function update(Request $request, Organization $organization, Customer $customer): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $organization->contacts()->updateExistingPivot($customer->id, [
            'role' => $data['role'],
            'can_charge_account' => (bool) ($data['can_charge_account'] ?? false),
        ]);

        return back();
    }

    public function destroy(Organization $organization, Customer $customer): RedirectResponse
    {
        if ($customer->organization_id === $organization->id && $customer->organizations()->count() <= 1) {
            OrderRules::fail("{$organization->name} is {$customer->full_name}’s only organization. Edit the contact to move them instead.");
        }

        $organization->contacts()->detach($customer->id);

        if ($customer->organization_id === $organization->id) {
            $customer->update(['organization_id' => $customer->organizations()->value('organizations.id')]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'role' => ['required', Rule::in(array_keys(CustomerMembership::ROLES))],
            'can_charge_account' => ['boolean'],
        ];
    }
}
