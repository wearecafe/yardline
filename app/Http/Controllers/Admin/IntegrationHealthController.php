<?php

namespace App\Http\Controllers\Admin;

use App\BackOffice\TenantMetrics;
use App\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendToQuickBooks;
use App\Models\QuickBooksSync;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Whether YardRunner's connections are working, across every tenant:
 * webhooks from Stripe, QuickBooks syncs and background jobs.
 */
class IntegrationHealthController extends Controller
{
    private const PROVIDERS = ['quickbooks' => 'QuickBooks Online', 'stripe' => 'Stripe'];

    public function index(TenantMetrics $metrics): Response
    {
        $now = CarbonImmutable::now();
        $since = $now->subDay();

        $deliveries = DB::table('webhook_deliveries')
            ->where('received_at', '>=', $since)
            ->groupBy('provider', 'outcome')
            ->selectRaw('provider, outcome, count(*) as n')
            ->get();

        $webhooks = [];

        foreach ($deliveries as $row) {
            $webhooks[(string) $row->provider][(string) $row->outcome] = (int) $row->n;
        }

        $integrations = $metrics->integrations();
        $backlog = $metrics->quickBooksBacklog();
        $failedJobs = DB::table('failed_jobs')->count();

        $connections = ['quickbooks' => [0, 0]];

        foreach ($integrations as $providers) {
            foreach ($providers as $provider => $integration) {
                if (isset($connections[$provider])) {
                    $connections[$provider][0]++;
                    $connections[$provider][1] += $integration['status'] === IntegrationStatus::Error->value ? 1 : 0;
                }
            }
        }

        $qbFailed = array_sum(array_column($backlog, 'failed'));
        $qbWaiting = array_sum(array_column($backlog, 'waiting'));

        return Inertia::render('admin/integrations', [
            'services' => [
                $this->webhookService('Stripe webhooks', $webhooks['stripe'] ?? []),
                $this->webhookService('Billing webhooks', $webhooks['stripe_billing'] ?? []),
                [
                    'name' => 'QuickBooks sync',
                    'value' => (string) $qbWaiting,
                    'detail' => "waiting · {$qbFailed} failed".($connections['quickbooks'][1] > 0 ? " · {$connections['quickbooks'][1]} connections with an error" : ''),
                    'tone' => $qbFailed > 0 || $connections['quickbooks'][1] > 0 ? 'danger' : ($qbWaiting > 0 ? 'warning' : 'success'),
                ],
                [
                    'name' => 'Background jobs',
                    'value' => (string) $failedJobs,
                    'detail' => 'failed jobs to look at',
                    'tone' => $failedJobs > 0 ? 'warning' : 'success',
                ],
            ],
            'issues' => $this->issues($metrics, $integrations, $backlog, $since),
            'hours' => $this->hours($now),
            'failedJobs' => DB::table('failed_jobs')
                ->latest('failed_at')
                ->limit(20)
                ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->map(function (object $job) {
                    /** @var array<string, mixed>|null $payload */
                    $payload = json_decode((string) $job->payload, true);
                    $name = is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'Job';

                    return [
                        'uuid' => (string) $job->uuid,
                        'job' => class_basename($name),
                        'queue' => (string) $job->queue,
                        'error' => Str::limit(Str::before((string) $job->exception, "\n"), 200),
                        'failed_at' => CarbonImmutable::parse((string) $job->failed_at)->toIso8601String(),
                    ];
                })
                ->values(),
        ]);
    }

    /**
     * Marks a tenant's failed QuickBooks records to send again and queues
     * them, as the tenant would from Settings → QuickBooks.
     */
    public function retryQuickBooks(Tenant $tenant): RedirectResponse
    {
        $count = CurrentTenant::run($tenant, function () {
            $failed = QuickBooksSync::query()->where('status', 'failed')->get();

            foreach ($failed as $sync) {
                $sync->forceFill(['status' => 'waiting', 'message' => 'Queued to send again by YardRunner staff.'])->save();
                SendToQuickBooks::dispatch($sync->syncable_type, $sync->syncable_id);
            }

            return $failed->count();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Queued {$count} QuickBooks ".Str::plural('record', $count)." for {$tenant->name} to send again."]);

        return back();
    }

    public function retryJob(string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Job queued to run again.']);

        return back();
    }

    public function forgetJob(string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:forget', ['id' => $uuid]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Failed job cleared.']);

        return back();
    }

    /**
     * @param  array<string, int>  $outcomes
     * @return array{name: string, value: string, detail: string, tone: string}
     */
    private function webhookService(string $name, array $outcomes): array
    {
        $received = array_sum($outcomes);
        $bad = ($outcomes[WebhookDelivery::FAILED] ?? 0) + ($outcomes[WebhookDelivery::REJECTED] ?? 0);

        return [
            'name' => $name,
            'value' => number_format($received),
            'detail' => $received === 0 ? 'none in 24 hours' : "in 24 hours · {$bad} failed or rejected",
            'tone' => $bad === 0 ? 'success' : ($bad / max($received, 1) > 0.05 ? 'danger' : 'warning'),
        ];
    }

    /**
     * Open problems, worst first.
     *
     * @param  array<int, array<string, array{status: string, account: string|null, last_synced_at: string|null, last_error: string|null, updated_at: string|null}>>  $integrations
     * @param  array<int, array{failed: int, waiting: int, oldest_failure: string|null}>  $backlog
     * @return list<array<string, mixed>>
     */
    private function issues(TenantMetrics $metrics, array $integrations, array $backlog, CarbonImmutable $since): array
    {
        $issues = [];

        foreach ($integrations as $tenantId => $providers) {
            foreach ($providers as $provider => $integration) {
                if ($integration['status'] === IntegrationStatus::Error->value) {
                    $issues[] = [
                        'key' => "integration-{$tenantId}-{$provider}",
                        'tenant_id' => $tenantId,
                        'integration' => self::PROVIDERS[$provider] ?? Str::headline($provider),
                        'tone' => 'danger',
                        'problem' => 'Connection has an error. The tenant needs to reconnect it in Settings.',
                        'detail' => $integration['last_error'],
                        'count' => null,
                        'since' => $integration['updated_at'],
                        'action' => null,
                    ];
                }
            }
        }

        foreach ($backlog as $tenantId => $counts) {
            if ($counts['failed'] > 0) {
                $issues[] = [
                    'key' => "quickbooks-{$tenantId}",
                    'tenant_id' => $tenantId,
                    'integration' => 'QuickBooks Online',
                    'tone' => 'danger',
                    'problem' => 'Records failed to send to QuickBooks.',
                    'detail' => null,
                    'count' => $counts['failed'].' '.Str::plural('record', $counts['failed']),
                    'since' => $counts['oldest_failure'],
                    'action' => 'retry_quickbooks',
                ];
            }
        }

        foreach ($metrics->webhookFailures($since) as $tenantId => $failures) {
            $issues[] = [
                'key' => "webhooks-{$tenantId}",
                'tenant_id' => $tenantId,
                'integration' => self::PROVIDERS[$failures['provider']] ?? Str::headline($failures['provider']),
                'tone' => 'danger',
                'problem' => 'Webhooks failed while YardRunner handled them.',
                'detail' => $failures['latest'],
                'count' => $failures['count'].' in 24 hours',
                'since' => null,
                'action' => null,
            ];
        }

        $rejected = WebhookDelivery::query()
            ->where('outcome', WebhookDelivery::REJECTED)
            ->where('received_at', '>=', $since)
            ->get(['provider', 'received_at']);

        foreach ($rejected->groupBy('provider') as $provider => $rows) {
            $issues[] = [
                'key' => "rejected-{$provider}",
                'tenant_id' => null,
                'integration' => self::PROVIDERS[$provider] ?? Str::headline((string) $provider),
                'tone' => 'warning',
                'problem' => 'Webhooks rejected: the signature didn’t match. Check the webhook secret in the environment.',
                'detail' => null,
                'count' => $rows->count().' in 24 hours',
                'since' => $rows->sortBy('received_at')->first()?->received_at->toIso8601String(),
                'action' => null,
            ];
        }

        $names = Tenant::query()
            ->whereIn('id', array_filter(array_column($issues, 'tenant_id')))
            ->pluck('name', 'id');

        return array_map(fn (array $issue) => [
            ...$issue,
            'tenant' => $issue['tenant_id'] === null ? null : ['id' => $issue['tenant_id'], 'name' => (string) ($names[(int) $issue['tenant_id']] ?? 'Unknown tenant')],
            'since' => $issue['since'] === null ? null : CarbonImmutable::parse($issue['since'])->toIso8601String(),
        ], $issues);
    }

    /**
     * Webhooks received and failed per hour, for the last 24 hours.
     *
     * @return list<array{hour: string, received: int, failed: int}>
     */
    private function hours(CarbonImmutable $now): array
    {
        $start = $now->startOfHour()->subHours(23);
        $hours = [];

        for ($i = 0; $i < 24; $i++) {
            $hour = $start->addHours($i);
            $hours[$hour->format('Y-m-d H')] = ['hour' => $hour->toIso8601String(), 'received' => 0, 'failed' => 0];
        }

        $rows = WebhookDelivery::query()
            ->where('received_at', '>=', $start)
            ->get(['outcome', 'received_at']);

        foreach ($rows as $row) {
            $key = $row->received_at->format('Y-m-d H');

            if (! isset($hours[$key])) {
                continue;
            }

            $hours[$key]['received']++;

            if (in_array($row->outcome, [WebhookDelivery::FAILED, WebhookDelivery::REJECTED], true)) {
                $hours[$key]['failed']++;
            }
        }

        return array_values($hours);
    }
}
