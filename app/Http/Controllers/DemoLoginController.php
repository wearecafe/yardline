<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Demo;
use Database\Seeders\DemoYardSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The demo yard's sign-in page (/demo). It can only sign people into
 * accounts that belong to the demo yard; real yards never see it.
 */
class DemoLoginController extends Controller
{
    public function show(): Response
    {
        $tenant = Demo::ensureTenant();
        abort_if($tenant === null, 404);

        $users = User::query()->where('tenant_id', $tenant->id)->orderBy('id')->get()->keyBy('email');

        $accounts = collect(DemoYardSeeder::ACCOUNTS)
            ->filter(fn (array $account) => $users->has($account['email']))
            ->map(fn (array $account) => [
                'email' => $account['email'],
                'name' => $account['name'],
                'role' => $account['title'],
                'description' => $account['description'],
            ])
            ->values();

        return Inertia::render('auth/demo', [
            'yard' => $tenant->name,
            'accounts' => $accounts,
            'portalUrl' => route('portal.login', $tenant->slug),
            'bookingUrl' => route('book.show', $tenant->slug),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $tenantId = Demo::enabled() ? Demo::tenantId() : null;
        abort_if($tenantId === null, 404);

        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->where('tenant_id', $tenantId)
            ->first();
        abort_if($user === null, 404);

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
