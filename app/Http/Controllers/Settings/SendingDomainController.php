<?php

namespace App\Http\Controllers\Settings;

use App\Audit\Audit;
use App\Email\Domains\DomainProblem;
use App\Email\Domains\SendingDomains;
use App\Email\MailGroup;
use App\Email\Sender;
use App\Http\Controllers\Controller;
use App\Models\SendingDomain;
use App\Models\Tenant;
use App\Notifications\SendingDomainRecordsMessage;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Settings → Sending domain: send customer emails from the yard's own
 * addresses. A white-label flow; nothing here names the email service.
 */
class SendingDomainController extends Controller
{
    private const MAILBOX = ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/i'];

    public function edit(): Response
    {
        $tenant = $this->tenant();
        $domain = SendingDomain::query()->first();

        return Inertia::render('settings/sending-domain', [
            'domain' => $domain === null ? null : [
                'domain' => $domain->domain,
                'status' => $domain->status,
                'status_label' => $domain->statusLabel(),
                'records' => array_map(fn (array $r) => [
                    'key' => $r['key'],
                    'label' => $r['label'],
                    'note' => $r['note'],
                    'type' => $r['type'],
                    'host' => $r['host'],
                    'value' => $r['value'],
                    'found' => (bool) $r['found'],
                    'optional' => (bool) ($r['optional'] ?? false),
                ], $domain->records ?? []),
                'mailboxes' => (object) ($domain->mailboxes ?? []),
                'sending_enabled' => $domain->sending_enabled,
                'is_sending' => $domain->isSending(),
                'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
                'checks_until' => $domain->checks_until?->toIso8601String(),
            ],
            'groups' => collect(MailGroup::cases())->map(fn (MailGroup $g) => [
                'value' => $g->value,
                'label' => $g->label(),
                'description' => $g->description(),
                'suggested' => $g->suggested(),
            ]),
            'fallback' => Sender::fallbackAddress($tenant),
        ]);
    }

    public function store(Request $request, SendingDomains $domains): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_if(SendingDomain::query()->exists(), 409);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/i'],
            'mailbox' => ['required', ...array_slice(self::MAILBOX, 1)],
        ], [
            'domain.regex' => 'Enter just the domain, like youryard.ca.',
            'mailbox.regex' => 'Use letters, numbers, dots, dashes or underscores.',
        ]);

        $name = strtolower($data['domain']);

        if (in_array($name, SendingDomains::FREE_MAIL, true)) {
            throw ValidationException::withMessages(['domain' => 'Use a domain your business owns. Free email addresses like Gmail can’t be used.']);
        }

        if ($name === 'yardrunner.ca' || str_ends_with($name, '.yardrunner.ca')) {
            throw ValidationException::withMessages(['domain' => 'Use your business’s own domain.']);
        }

        try {
            $domain = $domains->connect($tenant, $name, strtolower($data['mailbox']));
            $domains->check($domain);
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['domain' => DomainProblem::YARD_MESSAGE]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Add the records below to {$name}'s DNS."]);

        return back();
    }

    public function check(SendingDomains $domains): RedirectResponse
    {
        $domain = SendingDomain::query()->firstOrFail();

        try {
            $domain = $domains->restart($domain);
        } catch (Throwable $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'We couldn’t check just now. Try again in a minute.']);

            return back();
        }

        Inertia::flash('toast', ['type' => $domain->status === SendingDomain::VERIFIED ? 'success' : 'info', 'message' => match ($domain->status) {
            SendingDomain::VERIFIED => 'All records found. Your domain is verified.',
            SendingDomain::PARTIAL => 'Some records found. DNS changes can take a few hours to show up.',
            default => 'No records found yet. DNS changes can take a few hours to show up.',
        }]);

        return back();
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $domain = SendingDomain::query()->firstOrFail();

        $rules = ['sending_enabled' => ['sometimes', 'boolean']];

        foreach (MailGroup::cases() as $group) {
            $rules["mailboxes.{$group->value}"] = $group === MailGroup::Main ? ['required', ...array_slice(self::MAILBOX, 1)] : self::MAILBOX;
        }

        $data = $request->validate($rules, ['mailboxes.*.regex' => 'Use letters, numbers, dots, dashes or underscores.']);

        if (($data['sending_enabled'] ?? false) && $domain->status !== SendingDomain::VERIFIED) {
            throw ValidationException::withMessages(['sending_enabled' => 'Your domain needs to be verified first.']);
        }

        $before = ['mailboxes' => $domain->mailboxes, 'sending_enabled' => $domain->sending_enabled];

        if (isset($data['mailboxes'])) {
            $domain->mailboxes = collect($data['mailboxes'])->map(fn ($v) => $v === null ? '' : strtolower((string) $v))->filter()->all();
        }

        if (array_key_exists('sending_enabled', $data)) {
            $domain->sending_enabled = (bool) $data['sending_enabled'];
        }

        $domain->save();

        Audit::record($tenant, 'sending_domain.updated', [
            'mailboxes' => [json_encode($before['mailboxes']), json_encode($domain->mailboxes)],
            'sending_enabled' => [$before['sending_enabled'], $domain->sending_enabled],
        ], $domain->domain);

        Inertia::flash('toast', ['type' => 'success', 'message' => $domain->isSending()
            ? "Customer emails now come from {$domain->address(MailGroup::Main)} and your other mailboxes."
            : 'Email settings saved.']);

        return back();
    }

    public function destroy(SendingDomains $domains): RedirectResponse
    {
        $domain = SendingDomain::query()->firstOrFail();
        $domains->remove($domain);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Domain removed. Customer emails go from YardRunner’s address again.']);

        return back();
    }

    /**
     * Emails the records to whoever looks after the yard's DNS.
     */
    public function sendRecords(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $domain = SendingDomain::query()->firstOrFail();
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        Notification::route('mail', $data['email'])->notify(new SendingDomainRecordsMessage($domain, $tenant->name, (string) $request->user()?->name));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Records sent to {$data['email']}."]);

        return back();
    }

    private function tenant(): Tenant
    {
        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        return $tenant;
    }
}
