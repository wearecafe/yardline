<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PalletMovement;
use App\Notifications\StatementMessage;
use App\Orders\OrderRules;
use App\Receivables\Aging;
use App\Receivables\Statement;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who owes what, how late it is, and statements.
 */
class ReceivablesController extends Controller
{
    public function index(Aging $aging): Response
    {
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';
        $today = CarbonImmutable::today($tz);
        $rows = $aging->byOrganization($today);
        $totals = array_fill_keys(array_keys(Aging::BUCKETS), 0);

        foreach ($rows as $row) {
            foreach ($row['buckets'] as $key => $cents) {
                $totals[$key] += $cents;
            }
        }

        $walkIns = Invoice::query()->with('order')->where('status', 'open')->whereNull('organization_id')->get();

        return Inertia::render('receivables/index', [
            'rows' => $rows,
            'labels' => Aging::BUCKETS,
            'totals' => $totals,
            'stats' => [
                'owing_cents' => array_sum($totals),
                'overdue_cents' => array_sum($totals) - $totals['current'],
                'credits_cents' => (int) CreditNote::query()->where('status', 'open')->get()->sum(fn (CreditNote $c) => $c->remainingCents()),
                'pallets_out' => (int) PalletMovement::query()->sum('quantity'),
                'walk_in_cents' => (int) $walkIns->sum(fn (Invoice $i) => $i->balanceCents()),
            ],
            'credits' => CreditNote::query()->with(['organization:id,name', 'customer:id,first_name,last_name'])->latest('id')->limit(12)->get()->map(fn (CreditNote $c) => [
                'id' => $c->id,
                'number' => $c->number,
                'kind' => $c->kind,
                'who' => $c->organization->name ?? $c->customer->full_name ?? '—',
                'reason' => $c->reason,
                'issued_on' => $c->issued_on->toDateString(),
                'total_cents' => $c->total_cents,
                'remaining_cents' => $c->remainingCents(),
            ]),
        ]);
    }

    public function statement(Organization $organization, Statement $statement): Response
    {
        return Inertia::render('print/statement', [
            'statement' => $statement->for($organization),
            'business' => CurrentTenant::get()->name ?? 'Yardline',
            'canEmail' => filled($organization->email),
        ]);
    }

    public function pdf(Organization $organization, Statement $statement): HttpResponse
    {
        return response($this->render($statement->for($organization)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Statement '.$organization->account_number.'.pdf"',
        ]);
    }

    public function email(Organization $organization, Statement $statement): RedirectResponse
    {
        if (blank($organization->email)) {
            OrderRules::fail("{$organization->name} has no email address.");
        }

        $data = $statement->for($organization);

        if (! Demo::isCurrentTenant()) {
            Notification::route('mail', (string) $organization->email)
                ->notify(new StatementMessage($data, CurrentTenant::get()->name ?? 'Yardline', $this->render($data)));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Statement sent to {$organization->email}."]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(array $data): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('pdf.statement', ['s' => $data, 'tenant' => CurrentTenant::get()->name ?? 'Yardline'])->render());
        $dompdf->setPaper('letter');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
