<?php

namespace App\Http\Controllers\Time;

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
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who clocks in: people who sign in to Yardline and yard hands who only
 * punch at the counter tablet, with their PIN, pay type and payroll
 * number.
 */
class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $employees = Employee::query()
            ->with('user:id,name,email,role', 'location:id,name')
            ->orderByRaw('archived_at is not null')
            ->orderBy('name')
            ->get();

        $linked = $employees->pluck('user_id')->filter()->all();

        return Inertia::render('time/employees', [
            'employees' => $employees->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'job_title' => $e->job_title,
                'user' => $e->user ? ['name' => $e->user->name, 'email' => $e->user->email, 'role' => $e->user->role->label()] : null,
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
            // People who sign in but aren't set up for time yet.
            'users' => User::query()
                ->where('tenant_id', CurrentTenant::id())
                ->whereNotIn('id', $linked)
                ->orderBy('name')
                ->get(['id', 'name', 'role'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->label()]),
            'yards' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'payTypes' => collect(Employee::PAY_TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'photo' => TimeSettings::current()->photo,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        if (isset($data['user_id']) && Employee::query()->where('user_id', $data['user_id'])->exists()) {
            return back()->withErrors(['user_id' => 'That person is already set up.']);
        }

        $employee = new Employee($this->attributes($data));

        if (isset($data['user_id'])) {
            $employee->user_id = (int) $data['user_id'];
            $employee->name = $data['name'] ?? (string) User::query()->whereKey($data['user_id'])->value('name');
        }

        $employee->setPin($data['pin'] ?? null);
        $employee->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$employee->name} can clock in."]);

        return back();
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $this->validated($request, $employee);
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

    public function archive(Employee $employee): RedirectResponse
    {
        $employee->forceFill(['archived_at' => now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$employee->name} no longer clocks in. Their past hours stay on file."]);

        return back();
    }

    public function restore(Employee $employee): RedirectResponse
    {
        if ($employee->user_id !== null && Employee::query()->active()->where('user_id', $employee->user_id)->whereKeyNot($employee->id)->exists()) {
            return back()->withErrors(['employee' => 'That person is already set up again.']);
        }

        $employee->forceFill(['archived_at' => null])->save();

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
        $linked = Employee::query()->whereNotNull('user_id')->pluck('user_id')->all();
        $added = 0;

        User::query()
            ->where('tenant_id', CurrentTenant::id())
            ->whereNotIn('id', $linked)
            ->orderBy('id')
            ->get()
            ->each(function (User $user) use (&$added) {
                $owner = $user->role === UserRole::Owner;

                Employee::query()->create([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'job_title' => $user->isDriver() ? 'Driver' : null,
                    'pay_type' => $owner ? 'salaried' : 'hourly',
                    'tracks_time' => ! $owner,
                    'overtime_exempt' => $owner,
                ]);
                $added++;
            });

        return $added;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Employee $employee): array
    {
        return $request->validate([
            'user_id' => [$employee === null ? 'nullable' : 'prohibited', 'integer', Rule::exists('users', 'id')->where('tenant_id', CurrentTenant::id())],
            'name' => [$employee === null ? 'required_without:user_id' : 'required', 'nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:100'],
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
        $attributes = [
            'job_title' => $data['job_title'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'pay_type' => $data['pay_type'],
            'tracks_time' => (bool) ($data['tracks_time'] ?? true),
            'overtime_exempt' => (bool) ($data['overtime_exempt'] ?? false),
            'take_home_truck' => (bool) ($data['take_home_truck'] ?? false),
            'photo_on_punch' => (bool) ($data['photo_on_punch'] ?? false),
            'payroll_id' => ($data['payroll_id'] ?? '') !== '' ? $data['payroll_id'] : null,
        ];

        if (($data['name'] ?? '') !== '') {
            $attributes['name'] = $data['name'];
        }

        return $attributes;
    }
}
