<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Tenant;
use App\Notifications\PortalLinkMessage;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DemoYardSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contractors sign in with a link emailed to them: no passwords to keep.
 */
class PortalAuthController extends Controller
{
    public function show(Tenant $tenant): Response
    {
        return Inertia::render('portal/login', [
            'yard' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'demo' => $this->isDemo($tenant)
                ? CurrentTenant::run($tenant, fn () => $this->demoCustomer()?->only('id', 'first_name', 'last_name'))
                : null,
            'sent' => session('portal_sent'),
        ]);
    }

    public function send(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        // The same answer whether or not the address is known.
        CurrentTenant::run($tenant, function () use ($data, $tenant) {
            $customer = Customer::query()->whereRaw('lower(email) = ?', [strtolower($data['email'])])->orderByRaw('organization_id is null')->first();

            if ($customer !== null && ! Demo::isCurrentTenant()) {
                $url = URL::temporarySignedRoute('portal.enter', now()->addMinutes(30), ['tenant' => $tenant->slug, 'customer' => $customer->id]);
                $customer->notify(new PortalLinkMessage($url, $tenant->name));
            }
        });

        return back()->with('portal_sent', $data['email']);
    }

    public function enter(Request $request, Tenant $tenant, int $customer): RedirectResponse
    {
        $found = CurrentTenant::run($tenant, fn () => Customer::query()->find($customer));
        abort_if($found === null, 404);

        return $this->signIn($request, $tenant, $found);
    }

    /**
     * The demo yard lets visitors straight in as a contractor.
     */
    public function demo(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($this->isDemo($tenant), 404);

        $customer = CurrentTenant::run($tenant, fn () => $this->demoCustomer());
        abort_if($customer === null, 404);

        return $this->signIn($request, $tenant, $customer);
    }

    public function logout(Request $request): RedirectResponse
    {
        $slug = $request->session()->get('portal.slug');
        $request->session()->forget('portal');
        $request->session()->regenerateToken();

        return is_string($slug) ? to_route('portal.login', $slug) : redirect('/');
    }

    private function signIn(Request $request, Tenant $tenant, Customer $customer): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->put('portal', ['customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'slug' => $tenant->slug]);

        return to_route('portal.home');
    }

    private function isDemo(Tenant $tenant): bool
    {
        return Demo::available() && $tenant->slug === DemoYardSeeder::SLUG;
    }

    private function demoCustomer(): ?Customer
    {
        return Customer::query()->whereNotNull('organization_id')->whereNotNull('email')->orderBy('id')->first();
    }
}
