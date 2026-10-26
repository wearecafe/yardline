<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Tenancy\CurrentTenant;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/business', [
            'business' => ($tenant = CurrentTenant::get()) === null ? null : [
                ...$tenant->only('name', 'currency', 'timezone', 'stripe_account_id'),
                'reply_to_email' => $tenant->replyToEmail(),
            ],
            'timezones' => array_values(array_filter(
                DateTimeZone::listIdentifiers(DateTimeZone::AMERICA),
                fn (string $zone) => str_starts_with($zone, 'America/'),
            )),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'stripe_account_id' => ['nullable', 'string', 'starts_with:acct_', 'max:255'],
            'reply_to_email' => ['nullable', 'email', 'max:255'],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['reply_to_email'] = $data['reply_to_email'] ?? null;
        unset($data['reply_to_email']);

        $tenant->update([...$data, 'settings' => $settings]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Business details saved.']);

        return back();
    }
}
