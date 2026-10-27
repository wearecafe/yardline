<?php

namespace App\Http\Controllers\Settings;

use App\Access\AccessSettings;
use App\Audit\Audit;
use App\Enums\Permission;
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
        ];
        $tenant->update(['settings' => $settings]);

        // Who may price exempt products is the Discount exempt products
        // permission on each role (Settings → Roles). The owner always may.
        if (array_key_exists('exempt_price_roles', $data)) {
            $access = AccessSettings::for($tenant);
            $before = $access;

            foreach (PricingSettings::priceSettingRoles($tenant) as $role) {
                if ($role !== UserRole::Owner) {
                    $access = $access->withRolePermission($role, Permission::DiscountExempt, in_array($role->value, (array) $data['exempt_price_roles'], true));
                }
            }

            if ($access->toArray() !== $before->toArray()) {
                $access->save($tenant);
                Audit::record($tenant, 'updated', ['roles that can discount exempt products' => [
                    $this->labels($before),
                    $this->labels($access),
                ]]);
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pricing rules saved.']);

        return back();
    }

    private function labels(AccessSettings $access): string
    {
        return implode(', ', array_map(fn (UserRole $role) => $role->label(), $access->rolesGiving(Permission::DiscountExempt)));
    }
}
