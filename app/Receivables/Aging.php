<?php

namespace App\Receivables;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What each account owes, by how overdue it is, less unused credits.
 */
class Aging
{
    public const BUCKETS = ['current' => 'Current', 'd30' => '1–30', 'd60' => '31–60', 'd90' => '61–90', 'over' => '90+'];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function byOrganization(?CarbonImmutable $on = null): Collection
    {
        $on ??= CarbonImmutable::today();
        $invoices = Invoice::query()->with(['order', 'organization:id,name,account_number,credit_limit_cents,payment_terms,credit_hold'])
            ->where('status', 'open')
            ->whereNotNull('organization_id')
            ->get();
        $credits = CreditNote::query()->where('status', 'open')->whereNotNull('organization_id')->get()->groupBy('organization_id');

        return $invoices->groupBy('organization_id')->map(function (Collection $rows, $orgId) use ($on, $credits) {
            /** @var Organization $org */
            $org = $rows->first()->organization;
            $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);

            foreach ($rows as $invoice) {
                $buckets[self::bucket($invoice->daysOverdue($on))] += $invoice->balanceCents();
            }

            $credit = (int) ($credits->get($orgId)?->sum(fn (CreditNote $c) => $c->remainingCents()) ?? 0);
            $owing = array_sum($buckets);

            return [
                'organization_id' => $org->id,
                'name' => $org->name,
                'account_number' => $org->account_number,
                'terms' => $org->payment_terms->label(),
                'credit_hold' => $org->credit_hold,
                'buckets' => $buckets,
                'owing_cents' => $owing,
                'credit_cents' => $credit,
                'net_cents' => $owing - $credit,
                'oldest_days' => (int) $rows->max(fn (Invoice $i) => $i->daysOverdue($on)),
                'invoices' => $rows->count(),
            ];
        })->filter(fn ($row) => $row['owing_cents'] > 0)->sortByDesc('net_cents')->values();
    }

    public static function bucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'd30',
            $daysOverdue <= 60 => 'd60',
            $daysOverdue <= 90 => 'd90',
            default => 'over',
        };
    }
}
