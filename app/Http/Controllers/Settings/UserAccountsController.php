<?php

namespace App\Http\Controllers\Settings;

use App\Audit\Audit;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\UserInviteMessage;
use App\Tenancy\CurrentTenant;
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
 * The people who sign in to the yard: the owner adds admins and staff,
 * changes their role and removes them. Drivers have their own page
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
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'joined' => $user->email_verified_at !== null,
                    'is_you' => $user->id === $request->user()?->id,
                    'manageable' => $this->manageable($user, $request->user()),
                ])
                ->values(),
            'roles' => array_map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ], UserRole::assignable()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in($this->assignableValues())],
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
