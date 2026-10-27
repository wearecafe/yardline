<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Models\Location;
use App\Reports\ProfitFilters;
use App\Reports\ProfitReport;
use App\Tenancy\CurrentTenant;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The owner's profit and loss, down to gross profit. Only the owner (and
 * admins, if the owner allows) gets here: see the view-profit gate.
 */
class ProfitReportController extends Controller
{
    public const VIEWS = ['summary', 'categories', 'products', 'customers', 'units'];

    public function __construct(private readonly ProfitReport $report) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $comparison = $filters->comparison();
        $summary = $this->report->summary($filters);

        return Inertia::render('reports/profit', [
            'filters' => [
                ...$filters->toQuery(),
                'from' => $filters->fromDate(),
                'to' => $filters->toDate(),
                'label' => $filters->label(),
                'channel' => $filters->channel,
                'location_id' => $filters->locationId,
            ],
            'options' => [
                'periods' => $this->options(ProfitFilters::PERIODS),
                'compare' => $this->options(ProfitFilters::COMPARE),
                'business' => $this->options(ProfitFilters::BUSINESS),
                'channels' => $this->options(ProfitFilters::CHANNELS),
                'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            ],
            'lines' => collect(ProfitReport::LINES)->map(fn (array $line, string $key) => ['key' => $key, 'label' => $line[0], 'group' => $line[1]])->values(),
            'summary' => $summary,
            'comparison' => $comparison !== null ? [
                'label' => $comparison->label(),
                'summary' => $this->report->summary($comparison),
            ] : null,
            'trend' => $this->report->trend($filters),
            'memo' => $this->report->memo($filters),
            'categories' => $this->report->categories($filters),
            'products' => $this->report->products($filters),
            'customers' => $this->report->customers($filters),
            'units' => $this->report->rentalUnits($filters),
            'includes' => ['goods' => $filters->includesGoods(), 'rentals' => $filters->includesRentals()],
        ]);
    }

    /**
     * The records behind one amount on the statement.
     */
    public function detail(Request $request): JsonResponse
    {
        $data = $request->validate(['line' => ['required', Rule::in([...array_keys(ProfitReport::LINES), 'missing'])]]);
        $filters = $this->filters($request);

        return response()->json([
            'rows' => $this->report->detail((string) $data['line'], $filters),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $data = $request->validate(['view' => ['nullable', Rule::in(self::VIEWS)]]);
        $view = (string) ($data['view'] ?? 'summary');
        $filters = $this->filters($request);
        $rows = $this->rows($view, $filters);

        $this->audit("CSV ({$view})", $filters);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }

            fclose($out);
        }, 'Profit and loss '.$filters->label()." {$view}.csv", ['Content-Type' => 'text/csv']);
    }

    public function pdf(Request $request): HttpResponse
    {
        $filters = $this->filters($request);
        $comparison = $filters->comparison();

        $this->audit('PDF', $filters);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('pdf.profit', [
            'tenant' => CurrentTenant::get()->name ?? 'YardRunner',
            'label' => $filters->label(),
            'scope' => $this->scope($filters),
            'lines' => ProfitReport::LINES,
            'summary' => $this->report->summary($filters),
            'comparison' => $comparison !== null ? ['label' => $comparison->label(), 'summary' => $this->report->summary($comparison)] : null,
            'memo' => $this->report->memo($filters),
            'categories' => $this->report->categories($filters),
        ])->render());
        $dompdf->setPaper('letter');
        $dompdf->render();

        return response((string) $dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Profit and loss '.$filters->label().'.pdf"',
        ]);
    }

    private function filters(Request $request): ProfitFilters
    {
        $filters = ProfitFilters::fromInput($request->query(), CurrentTenant::get()->timezone ?? 'America/Toronto');

        // Shown with any error logged from here on, so a failure says which
        // report was asked for (period, yard, business, channel, view, line).
        Context::add('profit_report', [
            ...$filters->toQuery(),
            'from' => $filters->fromDate(),
            'to' => $filters->toDate(),
            'view' => $request->query('view'),
            'line' => $request->query('line'),
        ]);

        return $filters;
    }

    /**
     * Rows for a CSV of one view, header first. Money in dollars.
     *
     * @return list<list<string|int|float|null>>
     */
    private function rows(string $view, ProfitFilters $f): array
    {
        $d = fn (int $cents) => number_format($cents / 100, 2, '.', '');

        return match ($view) {
            'categories' => [
                ['Category', 'Sales', 'Cost', 'Gross profit', 'Margin %', 'Lines missing a cost'],
                ...array_map(fn ($r) => [$r['category'], $d($r['sales']), $d($r['cost']), $d($r['profit']), $r['margin'], $r['missing']], $this->report->categories($f)),
            ],
            'products' => [
                ['SKU', 'Product', 'Category', 'Quantity (base unit)', 'Unit', 'Sales', 'Cost', 'Gross profit', 'Margin %', 'Lines missing a cost'],
                ...array_map(fn ($r) => [$r['sku'], $r['name'], $r['category'], $r['quantity'], $r['unit'], $d($r['sales']), $d($r['cost']), $d($r['profit']), $r['margin'], $r['missing']], $this->report->products($f, 5000)),
            ],
            'customers' => [
                ['Account', 'Price list', 'Orders', 'Sales', 'Cost', 'Gross profit', 'Margin %', 'Discounts given'],
                ...array_map(fn ($r) => [$r['name'], $r['price_list'], $r['orders'], $d($r['sales']), $d($r['cost']), $d($r['profit']), $r['margin'], $d($r['discounts'])], $this->report->customers($f, 5000)),
            ],
            'units' => [
                ['Serial', 'Equipment', 'Rentals', 'Rental charges', 'Maintenance', 'Gross profit'],
                ...array_map(fn ($r) => [$r['serial'], $r['name'], $r['rentals'], $d($r['revenue']), $d($r['maintenance']), $d($r['profit'])], $this->report->rentalUnits($f)),
            ],
            default => $this->summaryRows($f),
        };
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function summaryRows(ProfitFilters $f): array
    {
        $d = fn (int $cents) => number_format($cents / 100, 2, '.', '');
        $summary = $this->report->summary($f);
        $rows = [['Profit and loss', $f->label()], ['Covers', $this->scope($f)], [], ['Line', 'Amount']];

        foreach (ProfitReport::LINES as $key => [$label, $group]) {
            if ($key === 'cogs') {
                $rows[] = ['Net revenue', $d($summary['net_revenue'])];
            }

            $rows[] = [$label, $d($summary['lines'][$key])];
        }

        $rows[] = ['Total direct costs', $d($summary['direct_costs'])];
        $rows[] = ['Gross profit', $d($summary['gross_profit'])];
        $rows[] = ['Gross margin %', $summary['margin']];

        return $rows;
    }

    /**
     * "All yards · Goods and rentals · All channels"
     */
    private function scope(ProfitFilters $f): string
    {
        $yard = $f->locationId !== null ? (Location::query()->find($f->locationId)->name ?? 'One yard') : 'All yards';

        return implode(' · ', [
            $yard,
            ProfitFilters::BUSINESS[$f->business],
            $f->channel !== null ? ProfitFilters::CHANNELS[$f->channel].' only' : 'All channels',
        ]);
    }

    private function audit(string $what, ProfitFilters $f): void
    {
        $tenant = CurrentTenant::get();

        if ($tenant !== null) {
            Audit::record($tenant, 'exported', note: "Profit and loss {$what}: {$f->label()} · {$this->scope($f)}");
        }
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private function options(array $labels): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys($labels), array_values($labels));
    }
}
