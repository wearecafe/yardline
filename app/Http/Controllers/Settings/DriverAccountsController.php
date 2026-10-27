<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Truck;
use App\Models\User;
use App\Notifications\DriverInviteMessage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Driver sign-ins: who drives, which trucks they're able to drive, and
 * when their phone last checked in. Drivers only ever see their own run.
 */
class DriverAccountsController extends Controller
{
    public function index(): Response
    {
        $trucks = Truck::query()->orderBy('position')->orderBy('name')->get();

        return Inertia::render('settings/drivers', [
            'drivers' => User::query()
                ->where('tenant_id', CurrentTenant::id())
                ->where('role', UserRole::Driver->value)
                ->with('drivableTrucks:id')
                ->orderBy('name')
                ->get()
                ->map(fn (User $driver) => [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'email' => $driver->email,
                    'joined' => $driver->email_verified_at !== null,
                    'truck_ids' => $trucks->whereIn('id', $driver->drivableTrucks->modelKeys())->pluck('id')->values(),
                    'signs_in_to' => $trucks->where('driver_id', $driver->id)->pluck('id')->values(),
                    'last_seen' => $trucks->where('driver_id', $driver->id)->whereNotNull('last_located_at')->sortByDesc('last_located_at')->first()?->last_located_at?->toIso8601String(),
                ]),
            'trucks' => $trucks->map(fn (Truck $t) => ['id' => $t->id, 'name' => $t->name, 'is_active' => $t->is_active])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            ...$this->truckRules(),
        ]);

        $driver = DB::transaction(function () use ($data) {
            $driver = User::query()->create([
                'tenant_id' => CurrentTenant::id(),
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => UserRole::Driver,
                // Replaced when they choose their own from the emailed link.
                'password' => Hash::make(Str::random(40)),
            ]);

            $driver->setDrivableTrucks($this->truckIds($data));

            return $driver;
        });

        $this->invite($driver);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent {$driver->name} a link to choose a password."]);

        return back();
    }

    public function update(Request $request, User $driver): RedirectResponse
    {
        $this->ensureDriver($driver);

        $data = $request->validate($this->truckRules());

        DB::transaction(fn () => $driver->setDrivableTrucks($this->truckIds($data)));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved the trucks {$driver->name} can drive."]);

        return back();
    }

    public function resend(User $driver): RedirectResponse
    {
        $this->ensureDriver($driver);
        $this->invite($driver);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent {$driver->name} a new link."]);

        return back();
    }

    public function destroy(User $driver): RedirectResponse
    {
        $this->ensureDriver($driver);

        DB::transaction(function () use ($driver) {
            DB::table('sessions')->where('user_id', $driver->id)->delete();
            $driver->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$driver->name} can no longer sign in."]);

        return back();
    }

    private function ensureDriver(User $driver): void
    {
        abort_unless($driver->tenant_id === CurrentTenant::id() && $driver->isDriver(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function truckRules(): array
    {
        return [
            'truck_ids' => ['present', 'array'],
            'truck_ids.*' => ['integer', 'distinct', TenantRule::exists('trucks')],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function truckIds(array $data): array
    {
        return array_values(array_map('intval', $data['truck_ids']));
    }

    private function invite(User $driver): void
    {
        $token = Password::broker()->createToken($driver);
        $url = route('password.reset', ['token' => $token, 'email' => $driver->email]);

        $driver->notify(new DriverInviteMessage($url, CurrentTenant::get()->name ?? config('app.name')));
    }
}
