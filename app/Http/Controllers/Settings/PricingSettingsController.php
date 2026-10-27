<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Pricing\PricingSettings;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Business-wide pricing rules: whether category discounts beat product
 * prices by default, and who may price exempt products by hand.
 */
class PricingSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $roles = array_map(fn (UserRole $role) => $role->value, PricingSettings::priceSettingRoles());

        $data = $request->validate([
            'category_rules_override' => ['boolean'],
            'exempt_price_roles' => ['array'],
            'exempt_price_roles.*' => ['string', Rule::in($roles)],
        ]);

        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        $settings = $tenant->settings ?? [];
        $settings['pricing'] = [
            'category_rules_override' => (bool) ($data['category_rules_override'] ?? false),
            'exempt_price_roles' => array_values(array_unique($data['exempt_price_roles'] ?? [])),
        ];
        $tenant->update(['settings' => $settings]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pricing rules saved.']);

        return back();
    }
}
