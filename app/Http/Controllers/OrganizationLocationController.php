<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationLocation;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Job sites and ship-to addresses on an account.
 */
class OrganizationLocationController extends Controller
{
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($organization, $data) {
            $site = $organization->locations()->create($data);
            $this->onlyDefault($organization, $site);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$data['name']} added."]);

        return back();
    }

    public function update(Request $request, Organization $organization, OrganizationLocation $site): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($organization, $site, $data) {
            $site->update($data);
            $this->onlyDefault($organization, $site);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$site->name} saved."]);

        return back();
    }

    public function destroy(Organization $organization, OrganizationLocation $site): RedirectResponse
    {
        // Orders keep their own copy of the address, so this is safe.
        $site->delete();

        return back();
    }

    private function onlyDefault(Organization $organization, OrganizationLocation $site): void
    {
        if ($site->is_default) {
            $organization->locations()->whereKeyNot($site->id)->update(['is_default' => false]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'delivery_notes' => ['nullable', 'string', 'max:2000'],
            'location_id' => ['nullable', TenantRule::exists('locations')],
            'po_required' => ['boolean'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]) + ['country' => 'CA'];
    }
}
