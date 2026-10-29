<?php

namespace App\Console\Commands;

use App\Email\Domains\SendingDomains;
use App\Models\SendingDomain;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Checks yards' sending domains: every run for domains still waiting for
 * their records (for 72 hours after they're connected), and hourly for
 * verified ones, so a removed record switches customer emails back to
 * YardRunner's address and tells the yard.
 */
class CheckSendingDomains extends Command
{
    protected $signature = 'yardrunner:email:domains';

    /** The name it had before the rename, kept so old habits and docs still work. */
    protected $aliases = ['yardline:email:domains'];

    protected $description = 'Check yards’ sending domains for their DNS records';

    public function handle(): int
    {
        $due = SendingDomain::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q
                // Past their 72 hours, these move to "expired" and drop out.
                ->whereIn('status', [SendingDomain::PENDING, SendingDomain::PARTIAL])
                ->orWhere(fn ($q) => $q
                    ->whereIn('status', [SendingDomain::VERIFIED, SendingDomain::FAILING])
                    ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subHour()))))
            ->with('tenant')
            ->get();

        foreach ($due as $domain) {
            if ($domain->tenant === null) {
                continue;
            }

            try {
                CurrentTenant::run($domain->tenant, fn () => app(SendingDomains::class)->check($domain));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Checked {$due->count()} sending domains.");

        return self::SUCCESS;
    }
}
