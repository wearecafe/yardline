<?php

namespace App\Http\Controllers\Settings;

use App\Audit\Audit;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\User;
use App\Notifications\UserInviteMessage;
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
 * The people who sign in to the yard: the owner adds admins, staff and
 * order pickers, changes their role and removes them. Order pickers work
 * the yards they're given, and can have a PIN for a shared yard tablet. Drivers have their own page
 * (Settings → Drivers) because they only ever see their own run.
 */
class UserAccountsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/users', [
            'users' => User::query()
                ->where('tenant_id', CurrentTenant::id())
                ->where('role', '!=', UserRole::Driver->value)
                ->orderByRaw('case role when ? then 0 when ? then 1 else 2 end', [UserRole::Owner->value, UserRole::Admin->value])
                ->orderBy('name')
                ->with('yards:id')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'joined' => $user->email_verified_at !== null,
                    'is_you' => $user->id === $request->user()?->id,
                    'manageable' => $this->manageable($user, $request->user()),
                    'yards' => $user->isPicker() ? $user->yards->pluck('id')->map(fn ($id) => (int) $id)->values() : [],
                    'has_pin' => $user->isPicker() && $user->pin !== null,
                ])
                ->values(),
            'roles' => array_map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ], UserRole::assignable()),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in($this->assignableValues())],
            ...$this->pickerRules($request->input('role')),
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'tenant_id' => CurrentTenant::id(),
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => UserRole::from($data['role']),
                // Replaced when they choose their own from the emailed link.
                'password' => Hash::make(Str::random(40)),
            ]);

            Audit::record($user, 'created', ['role' => [null, $user->role->value]]);
            $this->savePicking($user, $data);

            return $user;
        });

        $this->invite($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent {$user->name} a link to choose a password."]);

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensureManageable($user, $request->user());

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'role' => ['sometimes', 'required', Rule::in($this->assignableValues())],
            ...$this->pickerRules($request->input('role', $user->role->value)),
        ]);

        $user->fill([
            ...(isset($data['name']) ? ['name' => $data['name']] : []),
            ...(isset($data['role']) ? ['role' => UserRole::from($data['role'])] : []),
        ]);

        if ($user->isDirty()) {
            $changes = [];

            foreach (['name', 'role'] as $field) {
                if ($user->isDirty($field)) {
                    $original = $user->getOriginal($field);
                    $changes[$field] = [
                        $original instanceof UserRole ? $original->value : $original,
                        $field === 'role' ? $user->role->value : $user->name,
                    ];
                }
            }

            $user->save();
            Audit::record($user, 'updated', $changes);

            Inertia::flash('toast', ['type' => 'success', 'message' => "Updated {$user->name}."]);
        }

        if ($this->savePicking($user, $data)) {
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
            Audit::record($user, 'deleted', ['role' => [$user->role->value, null]]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} can no longer sign in."]);

        return back();
    }

    /**
     * Admins and staff in this business. Not the owner (one per business),
     * not yourself, and not drivers (Settings → Drivers).
     */
    private function manageable(User $user, ?User $actor): bool
    {
        return $actor !== null
            && $user->tenant_id === CurrentTenant::id()
            && $user->id !== $actor->id
            && in_array($user->role, UserRole::assignable(), true);
    }

    private function ensureManageable(User $user, ?User $actor): void
    {
        abort_unless($this->manageable($user, $actor), 404);
    }

    /**
     * An order picker works at least one yard. A PIN is optional.
     *
     * @return array<string, mixed>
     */
    private function pickerRules(mixed $role): array
    {
        if ($role !== UserRole::Picker->value) {
            return [];
        }

        return [
            'location_ids' => ['sometimes', 'array', 'min:1'],
            'location_ids.*' => ['integer', TenantRule::exists('locations')],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,6}$/'],
            'clear_pin' => ['boolean'],
        ];
    }

    /**
     * Yards and PIN for an order picker; anyone else has neither.
     *
     * @param  array<string, mixed>  $data
     * @return bool whether anything changed
     */
    private function savePicking(User $user, array $data): bool
    {
        if (! $user->isPicker()) {
            $had = $user->yards()->exists() || $user->pin !== null;
            $user->setYards([]);
            $user->forceFill(['pin' => null])->save();

            return $had;
        }

        $changed = false;

        // A new order picker works every yard until they're given fewer.
        if (! array_key_exists('location_ids', $data) && $user->yards()->doesntExist()) {
            $data['location_ids'] = Location::query()->pluck('id')->all();
        }

        if (array_key_exists('location_ids', $data)) {
            $before = $user->yards()->pluck('locations.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $after = collect($data['location_ids'])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            if ($before !== $after) {
                $user->setYards($after);
                Audit::record($user, 'updated', ['yards' => [
                    Location::query()->whereIn('id', $before)->orderBy('name')->pluck('name')->implode(', '),
                    Location::query()->whereIn('id', $after)->orderBy('name')->pluck('name')->implode(', '),
                ]]);
                $changed = true;
            }
        }

        if (filled($data['pin'] ?? null)) {
            $user->forceFill(['pin' => $data['pin']])->save();
            Audit::record($user, 'PIN set');
            $changed = true;
        } elseif (! empty($data['clear_pin']) && $user->pin !== null) {
            $user->forceFill(['pin' => null])->save();
            Audit::record($user, 'PIN removed');
            $changed = true;
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    private function assignableValues(): array
    {
        return array_map(fn (UserRole $role) => $role->value, UserRole::assignable());
    }

    private function invite(User $user): void
    {
        $token = Password::broker()->createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

        $user->notify(new UserInviteMessage($url, CurrentTenant::get()->name ?? config('app.name'), $user->role->label()));
    }
}
