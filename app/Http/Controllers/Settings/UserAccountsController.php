<?php

namespace App\Http\Controllers\Settings;

use App\Access\AccessSettings;
use App\Audit\Audit;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\User;
use App\Notifications\UserInviteMessage;
use App\Reports\ReportSettings;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
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
 * The people who sign in to the yard. Anyone with Manage users and roles
 * (owner and admin by default) adds people, gives them one or more roles,
 * the yards they work and any permissions on top of their roles, and
 * removes them. People who only drive are on Settings → Drivers.
 *
 * Nobody manages the owner or themselves here, and only the owner hands
 * out See gross-profit P&L.
 */
class UserAccountsController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('settings/users', [
            'users' => User::query()
                ->where('tenant_id', CurrentTenant::id())
                ->with('yards:id')
                ->get()
                ->reject(fn (User $user) => $user->isOnlyDriver())
                ->sortBy([fn (User $a, User $b) => $a->role->rank() <=> $b->role->rank(), fn (User $a, User $b) => strcasecmp($a->name, $b->name)])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'roles' => array_map(fn (UserRole $r) => $r->value, $user->roleList()),
                    'joined' => $user->email_verified_at !== null,
                    'is_you' => $user->id === $actor->id,
                    'manageable' => $this->manageable($user, $actor),
                    'yard_scoped' => $user->isYardScoped(),
                    'yards' => $user->yards->pluck('id')->map(fn ($id) => (int) $id)->values(),
                    // Granted (true) or removed (false) on top of their roles.
                    'permissions' => (object) ($user->permissions ?? []),
                    'effective' => array_map(fn (Permission $p) => $p->value, $user->permissionList()),
                ])
                ->values(),
            'roles' => array_map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
                'yard_scoped' => $role->isYardScoped(),
            ], UserRole::assignable()),
            'permissions' => array_map(fn (Permission $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'description' => $p->description(),
                'grantable' => $this->canGrant($p, $actor),
                // Roles that give it in this business.
                'roles' => array_map(fn (UserRole $r) => $r->value, AccessSettings::current()->rolesGiving($p)),
            ], Permission::cases()),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'reports' => ReportSettings::current()->toArray(),
            'isOwner' => $actor->hasRole(UserRole::Owner),
        ]);
    }

    /**
     * Whether admins see the profit report (costs and margins). The owner
     * always does. Only the owner can change it.
     */
    public function reports(Request $request): RedirectResponse
    {
        $data = $request->validate(['admins_can_view_profit' => ['required', 'boolean']]);
        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);
        abort_unless($request->user()?->hasRole(UserRole::Owner), 403);

        $before = ReportSettings::for($tenant)->adminsCanViewProfit;
        $after = (bool) $data['admins_can_view_profit'];
        AccessSettings::for($tenant)->withRolePermission(UserRole::Admin, Permission::SeeProfit, $after)->save($tenant);

        if ($before !== $after) {
            Audit::record($tenant, 'updated', ['admins can view profit report' => [$before ? 'yes' : 'no', $after ? 'yes' : 'no']]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $after ? 'Admins can now see the profit report.' : 'Only you can see the profit report now.']);

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            ...$this->roleRules(required: true),
            ...$this->yardRules(),
            ...$this->permissionRules(),
        ]);

        $user = DB::transaction(function () use ($data, $actor) {
            $user = new User([
                'tenant_id' => CurrentTenant::id(),
                'name' => $data['name'],
                'email' => $data['email'],
                // Replaced when they choose their own from the emailed link.
                'password' => Hash::make(Str::random(40)),
            ]);
            $user->setRoles($this->roles($data))->save();

            Audit::record($user, 'created', ['roles' => [null, $this->roleLabels($user)]]);

            // People who work only some yards start with every yard.
            $this->saveYards($user, [
                'location_ids' => $data['location_ids'] ?? Location::query()->pluck('id')->all(),
            ], audit: false);
            $this->savePermissions($user, $data, $actor);

            return $user;
        });

        $this->invite($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent {$user->name} a link to choose a password."]);

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->ensureManageable($user, $actor);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            ...$this->roleRules(required: false),
            ...$this->yardRules(),
            ...$this->permissionRules(),
        ]);

        $changed = DB::transaction(function () use ($user, $data, $actor) {
            $changes = [];

            if (isset($data['name']) && $data['name'] !== $user->name) {
                $changes['name'] = [$user->name, $data['name']];
                $user->name = $data['name'];
            }

            if (isset($data['roles'])) {
                $before = $this->roleLabels($user);
                $user->setRoles($this->roles($data));
                $after = $this->roleLabels($user);

                if ($before !== $after) {
                    $changes['roles'] = [$before, $after];
                }
            }

            if ($changes !== []) {
                $user->save();
                Audit::record($user, 'updated', $changes);
            }

            $yards = $this->saveYards($user, $data);
            $permissions = $this->savePermissions($user, $data, $actor);

            return $changes !== [] || $yards || $permissions;
        });

        if ($changed) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "Updated {$user->name}."]);
        }

        return back();
    }

    public function resend(Request $request, User $user): RedirectResponse
    {
        $this->ensureManageable($user, $request->user());
        $this->invite($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent {$user->name} a new link."]);

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->ensureManageable($user, $request->user());

        DB::transaction(function () use ($user) {
            Audit::record($user, 'deleted', ['roles' => [$this->roleLabels($user), null]]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} can no longer sign in."]);

        return back();
    }

    /**
     * Anyone in this business but the owner, yourself, and people who only
     * drive (Settings → Drivers).
     */
    private function manageable(User $user, ?User $actor): bool
    {
        return $actor !== null
            && $user->tenant_id === CurrentTenant::id()
            && $user->id !== $actor->id
            && ! $user->hasRole(UserRole::Owner)
            && ! $user->isOnlyDriver();
    }

    private function ensureManageable(User $user, ?User $actor): void
    {
        abort_unless($this->manageable($user, $actor), 404);
    }

    /** Admin hands out every permission except See gross-profit P&L. */
    private function canGrant(Permission $permission, User $actor): bool
    {
        return ! $permission->ownerGrantsOnly() || $actor->hasRole(UserRole::Owner);
    }

    /**
     * @return array<string, mixed>
     */
    private function roleRules(bool $required): array
    {
        return [
            'roles' => [$required ? 'required' : 'sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(array_map(fn (UserRole $r) => $r->value, UserRole::assignable()))],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function yardRules(): array
    {
        return [
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['integer', TenantRule::exists('locations')],
        ];
    }

    /**
     * Each permission: true to grant it, false to remove it, null to
     * follow their roles.
     *
     * @return array<string, mixed>
     */
    private function permissionRules(): array
    {
        return [
            'permissions' => ['sometimes', 'array'],
            ...collect(Permission::cases())->mapWithKeys(fn (Permission $p) => ["permissions.{$p->value}" => ['nullable', 'boolean']])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<UserRole>
     */
    private function roles(array $data): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => UserRole::fromValue((string) $value),
            (array) ($data['roles'] ?? []),
        )));
    }

    private function roleLabels(User $user): string
    {
        return implode(', ', array_map(fn (UserRole $r) => $r->label(), $user->roleList()));
    }

    /**
     * The yards someone works. Owners and admins work every yard, so
     * theirs are kept only in case their roles change.
     *
     * @param  array<string, mixed>  $data
     * @return bool whether anything changed
     */
    private function saveYards(User $user, array $data, bool $audit = true): bool
    {
        if (! array_key_exists('location_ids', $data)) {
            return false;
        }

        $before = $user->yards()->pluck('locations.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $after = collect(array_map(fn ($id) => (int) $id, (array) $data['location_ids']))->unique()->sort()->values()->all();

        if ($before === $after) {
            return false;
        }

        $user->setYards($after);

        if ($audit) {
            Audit::record($user, 'updated', ['yards' => [
                Location::query()->whereIn('id', $before)->orderBy('name')->pluck('name')->implode(', '),
                Location::query()->whereIn('id', $after)->orderBy('name')->pluck('name')->implode(', '),
            ]]);
        }

        return true;
    }

    /**
     * Permissions granted or removed on this person, on top of their roles.
     *
     * @param  array<string, mixed>  $data
     * @return bool whether anything changed
     */
    private function savePermissions(User $user, array $data, User $actor): bool
    {
        if (! array_key_exists('permissions', $data)) {
            return false;
        }

        $own = $user->permissions ?? [];
        $changes = [];

        foreach (Permission::cases() as $permission) {
            if (! array_key_exists($permission->value, (array) $data['permissions'])) {
                continue;
            }

            $value = $data['permissions'][$permission->value];
            $value = $value === null ? null : (bool) $value;
            $current = array_key_exists($permission->value, $own) ? (bool) $own[$permission->value] : null;

            if ($value === $current) {
                continue;
            }

            if (! $this->canGrant($permission, $actor)) {
                abort(403, "Only the owner can change {$permission->label()}.");
            }

            if ($value === null) {
                unset($own[$permission->value]);
            } else {
                $own[$permission->value] = $value;
            }

            $describe = fn (?bool $v) => match ($v) {
                true => 'granted',
                false => 'removed',
                null => 'from roles',
            };
            $changes[$permission->label()] = [$describe($current), $describe($value)];
        }

        if ($changes === []) {
            return false;
        }

        $user->permissions = $own === [] ? null : $own;
        $user->save();
        Audit::record($user, 'permissions changed', $changes);

        return true;
    }

    private function invite(User $user): void
    {
        $token = Password::broker()->createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

        $user->notify(new UserInviteMessage($url, CurrentTenant::get()->name ?? config('app.name'), $this->roleLabels($user)));
    }
}
