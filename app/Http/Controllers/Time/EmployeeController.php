<?php

namespace App\Http\Controllers\Time;

use App\Access\UserAccounts;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use App\Time\TimeSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who clocks in, with their PIN, pay type, home yard and payroll number.
 *
 * Every employee is a user: pick someone who already has one, or add a
 * new user here. People who only punch at the counter tablet are users
 * with the Time & attendance only role and no email. Only people who can
 * manage users add anyone with more than that.
 *
 * Archiving an employee ends their employment and keeps their hours; their
 * user stays. Deactivating the user (Settings → Users) archives them too.
 */
class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $employees = Employee::query()
            ->with('user.yards:id', 'location:id,name')
            ->orderByRaw('archived_at is not null')
            ->orderByName()
            ->get();

        $linked = $employees->pluck('user_id')->all();

        return Inertia::render('time/employees', [
            'employees' => $employees->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'user' => $this->person($e->user),
                'location_id' => $e->location_id,
                'yard' => $e->location?->name,
                'pay_type' => $e->pay_type,
                'tracks_time' => $e->tracks_time,
                'overtime_exempt' => $e->overtime_exempt,
                'take_home_truck' => $e->take_home_truck,
                'photo_on_punch' => $e->photo_on_punch,
                'payroll_id' => $e->payroll_id,
                'has_pin' => $e->hasPin(),
                'archived' => $e->archived_at !== null,
            ]),
            // Users who aren't set up for time yet.
            'users' => User::query()
                ->where('tenant_id', CurrentTenant::id())
                ->whereNotIn('id', $linked)
                ->with('yards:id')
                ->orderBy('name')
                ->get()
                ->map(fn (User $u) => $this->person($u)),
            // Roles a new user added here can have.
            'roles' => array_map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
                'yard_scoped' => $role->isYardScoped(),
            ], $this->givableRoles($request->user())),
            'yards' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'payTypes' => collect(Employee::PAY_TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'photo' => TimeSettings::current()->photo,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'user_id' => ['required_without:new_user', 'nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', CurrentTenant::id())->whereNull('deactivated_at')],
            'new_user' => ['required_without:user_id', 'nullable', 'array'],
        ], ['user_id.required_without' => 'Pick someone, or add a new user.']);

        $data = $this->validated($request);
        /** @var array<string, mixed>|null $newUser */
        $newUser = $request->filled('user_id') ? null : (array) $request->validate(UserAccounts::rules('new_user.', $this->givableRoles($request->user())))['new_user'];

        $employee = DB::transaction(function () use ($request, $data, $newUser) {
            if ($newUser !== null) {
                UserAccounts::ensureRolesFit(UserAccounts::roles($newUser), isset($newUser['email']) ? (string) $newUser['email'] : null, 'new_user.');
                $user = UserAccounts::create($newUser);
            } else {
                $user = User::query()->findOrFail((int) $request->input('user_id'));

                if (Employee::query()->where('user_id', $user->id)->exists()) {
                    throw ValidationException::withMessages(['user_id' => 'That person is already set up. Bring them back from “No longer here” instead.']);
                }
            }

            $this->ensureHomeYard($user, $data['location_id'] ?? null);

            $employee = new Employee($this->attributes($data));
            $employee->user_id = $user->id;
            $employee->setRelation('user', $user);
            $employee->setPin($data['pin'] ?? null);
            $employee->save();

            return $employee;
        });

        $message = $newUser !== null && $employee->user->canSignIn()
            ? "{$employee->name} can clock in. We emailed them a link to choose a password."
            : "{$employee->name} can clock in.";
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $this->validated($request);
        $this->ensureHomeYard($employee->user, $data['location_id'] ?? null);
        $employee->fill($this->attributes($data));

        if (! empty($data['clear_pin'])) {
            $employee->setPin(null);
        } elseif (! empty($data['pin'])) {
            $employee->setPin((string) $data['pin']);
        }

        $employee->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$employee->name} saved."]);

        return back();
    }

    /**
     * Their employment ends: they stop clocking in and their hours stay on
     * file. Their user, and any sign-in, stays.
     */
    public function archive(Employee $employee): RedirectResponse
    {
        $employee->forceFill(['archived_at' => now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$employee->name} no longer clocks in. Their past hours stay on file."]);

        return back();
    }

    /**
     * Back at work. If their user was deactivated, it comes back too;
     * bringing back someone who signs in takes Manage users and roles.
     */
    public function restore(Request $request, Employee $employee): RedirectResponse
    {
        $user = $employee->user;
        /** @var User $actor */
        $actor = $request->user();

        if ($user->deactivated_at !== null && $user->email !== null && ! $actor->canManageUsers()) {
            return back()->withErrors(['employee' => "{$user->name} signs in to YardRunner. Ask someone who manages users to bring them back."]);
        }

        DB::transaction(function () use ($employee, $user) {
            UserAccounts::reactivate($user);
            $employee->forceFill(['archived_at' => null])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$employee->name} can clock in again."]);

        return back();
    }

    /**
     * Sets everyone who signs in up to clock in: drivers and staff hourly,
     * the owner salaried and not tracked.
     */
    public function addEveryone(): RedirectResponse
    {
        $added = self::provision();

        Inertia::flash('toast', ['type' => 'success', 'message' => $added === 1 ? 'Added 1 person.' : "Added {$added} people."]);

        return back();
    }

    public static function provision(): int
    {
        $linked = Employee::query()->pluck('user_id')->all();
        $added = 0;

        User::query()
            ->where('tenant_id', CurrentTenant::id())
            ->whereNotIn('id', $linked)
            ->orderBy('id')
            ->get()
            ->each(function (User $user) use (&$added) {
                $owner = $user->hasRole(UserRole::Owner);

                Employee::query()->create([
                    'user_id' => $user->id,
                    'pay_type' => $owner ? 'salaried' : 'hourly',
                    'tracks_time' => ! $owner,
                    'overtime_exempt' => $owner,
                ]);
                $added++;
            });

        return $added;
    }

    /**
     * Roles a new user added here can have: any, for people who manage
     * users; otherwise only Time & attendance only.
     *
     * @return list<UserRole>
     */
    private function givableRoles(?User $actor): array
    {
        return $actor?->canManageUsers() ? UserRole::assignable() : [UserRole::TimeOnly];
    }

    /**
     * @return array<string, mixed>
     */
    private function person(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => array_map(fn (UserRole $r) => $r->label(), $user->roleList()),
            'time_only' => $user->isTimeOnly(),
            // Yards they work, or null for every yard (owner and admin).
            'yards' => $user->isYardScoped() ? $user->yards->pluck('id')->map(fn ($id) => (int) $id)->values()->all() : null,
            'deactivated' => $user->deactivated_at !== null,
        ];
    }

    /** A home yard has to be one they work. */
    private function ensureHomeYard(User $user, mixed $locationId): void
    {
        if ($locationId !== null && ! $user->worksAt((int) $locationId)) {
            throw ValidationException::withMessages(['location_id' => "{$user->name} doesn’t work that yard. Pick one of theirs, or add the yard to them under Settings → Users."]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'location_id' => ['nullable', 'integer', TenantRule::exists('locations')],
            'pay_type' => ['required', Rule::in(array_keys(Employee::PAY_TYPES))],
            'tracks_time' => ['boolean'],
            'overtime_exempt' => ['boolean'],
            'take_home_truck' => ['boolean'],
            'photo_on_punch' => ['boolean'],
            'payroll_id' => ['nullable', 'string', 'max:50'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,6}$/'],
            'clear_pin' => ['boolean'],
        ], ['pin.regex' => 'A PIN is 4 to 6 digits.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'location_id' => $data['location_id'] ?? null,
            'pay_type' => $data['pay_type'],
            'tracks_time' => (bool) ($data['tracks_time'] ?? true),
            'overtime_exempt' => (bool) ($data['overtime_exempt'] ?? false),
            'take_home_truck' => (bool) ($data['take_home_truck'] ?? false),
            'photo_on_punch' => (bool) ($data['photo_on_punch'] ?? false),
            'payroll_id' => ($data['payroll_id'] ?? '') !== '' ? $data['payroll_id'] : null,
        ];
    }
}
