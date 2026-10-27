<?php

namespace App\Http\Controllers\Settings;

use App\Access\AccessSettings;
use App\Audit\Audit;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Roles: what each role gives by default in this business,
 * and how much each role can refund before someone has to approve it.
 * Changes apply to everyone holding the role, except where a permission
 * was granted or removed on the person (Settings → Users).
 */
class RoleSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $access = AccessSettings::current();

        return Inertia::render('settings/roles', [
            'roles' => array_map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
                'editable' => $role !== UserRole::Owner,
                'permissions' => array_map(fn (Permission $p) => $p->value, $access->permissionsFor($role)),
                'refund_limit_cents' => $access->refundLimit($role),
            ], array_values(array_filter(UserRole::cases(), fn (UserRole $role) => $role !== UserRole::TimeOnly))),
            'permissions' => array_map(fn (Permission $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'description' => $p->description(),
                'requires' => $p->requires()?->value,
                'editable' => ! $p->ownerGrantsOnly() || $actor->hasRole(UserRole::Owner),
            ], Permission::cases()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        // Time & attendance only never gives permissions or refunds.
        $roleValues = array_map(fn (UserRole $r) => $r->value, array_filter(UserRole::assignable(), fn (UserRole $r) => $r !== UserRole::TimeOnly));
        $permissionValues = array_map(fn (Permission $p) => $p->value, Permission::cases());

        $data = $request->validate([
            'role_permissions' => ['sometimes', 'array:'.implode(',', $roleValues)],
            'role_permissions.*' => ['array'],
            'role_permissions.*.*' => ['string', 'in:'.implode(',', $permissionValues)],
            'refund_limits' => ['sometimes', 'array:'.implode(',', $roleValues)],
            'refund_limits.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $before = AccessSettings::for($tenant);
        $access = $before;
        $changes = [];

        foreach ((array) ($data['role_permissions'] ?? []) as $value => $list) {
            $role = UserRole::from((string) $value);
            $wanted = array_values(array_filter(array_map(fn ($p) => Permission::tryFrom((string) $p), (array) $list)));

            // Only the owner hands out the P&L; anyone else leaves it as it was.
            foreach (Permission::cases() as $permission) {
                if ($permission->ownerGrantsOnly() && ! $actor->hasRole(UserRole::Owner)) {
                    $wanted = array_values(array_filter($wanted, fn (Permission $p) => $p !== $permission));

                    if ($before->roleGives($role, $permission)) {
                        $wanted[] = $permission;
                    }
                }
            }

            $access = $access->withRolePermissions($role, $wanted);
            $was = $this->labels($before->permissionsFor($role));
            $now = $this->labels($access->permissionsFor($role));

            if ($was !== $now) {
                $changes["{$role->label()} permissions"] = [$was, $now];
            }
        }

        foreach ((array) ($data['refund_limits'] ?? []) as $value => $amount) {
            $role = UserRole::from((string) $value);
            $cents = $amount === null || $amount === '' ? null : (int) Money::parse($amount);
            $access = $access->withRefundLimit($role, $cents);

            if ($before->refundLimit($role) !== $access->refundLimit($role)) {
                $changes["{$role->label()} refund limit"] = [$this->limit($before->refundLimit($role)), $this->limit($access->refundLimit($role))];
            }
        }

        if ($changes !== []) {
            $access->save($tenant);
            Audit::record($tenant, 'roles updated', $changes);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Roles saved.']);

        return back();
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function labels(array $permissions): string
    {
        return implode(', ', array_map(fn (Permission $p) => $p->label(), $permissions)) ?: 'none';
    }

    private function limit(?int $cents): string
    {
        return $cents === null ? 'no limit' : Money::format($cents);
    }
}
