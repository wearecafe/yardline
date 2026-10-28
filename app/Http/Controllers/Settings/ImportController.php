<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Imports\Column;
use App\Imports\CsvFile;
use App\Imports\ImportKind;
use App\Imports\ImportRunner;
use App\Models\Import;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationLocation;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockLevel;
use App\Support\Files;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Settings → Import data: load a yard's existing data from spreadsheets,
 * checked first, then imported.
 */
class ImportController extends Controller
{
    public function index(Request $request): Response
    {
        $have = [
            ImportKind::Products->value => Product::query()->count(),
            ImportKind::PriceLists->value => PriceList::query()->count(),
            ImportKind::Organizations->value => Organization::query()->count(),
            ImportKind::JobSites->value => OrganizationLocation::query()->count(),
            ImportKind::Stock->value => StockLevel::query()->where('on_hand', '!=', 0)->count(),
            ImportKind::Balances->value => Invoice::query()->where('number', 'like', 'OB-%')->count(),
        ];

        return Inertia::render('settings/imports', [
            'kinds' => array_map(fn (ImportKind $kind) => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
                'needs' => $kind->needs(),
                'have' => $have[$kind->value],
                'columns' => array_map(fn (Column $c) => ['key' => $c->key, 'help' => $c->help, 'required' => $c->required], $kind->columns()),
            ], ImportKind::cases()),
            'selected' => ImportKind::tryFrom($request->string('kind')->toString())?->value,
            'fromSetup' => $request->boolean('setup'),
            'history' => Import::query()->with('creator:id,name')->latest('id')->limit(20)->get()->map(fn (Import $i) => $this->summary($i)),
            'maxRows' => CsvFile::MAX_ROWS,
        ]);
    }

    /** The blank template: just the headings. */
    public function template(ImportKind $kind): HttpResponse
    {
        return $this->download($kind->template(), 'yardrunner-'.str_replace('_', '-', $kind->value).'-template.csv');
    }

    /** A filled-in sample, to see how rows are laid out or to try an import. */
    public function sample(ImportKind $kind): HttpResponse
    {
        return $this->download($kind->sample(), 'yardrunner-'.str_replace('_', '-', $kind->value).'-sample.csv');
    }

    private function download(string $csv, string $filename): HttpResponse
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function store(Request $request, ImportRunner $runner): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(ImportKind::class)],
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
            'setup' => ['boolean'],
        ], [
            'file.mimes' => 'Upload a CSV file. In Excel or Google Sheets, save or download the sheet as CSV.',
            'file.max' => 'Keep the file under 5 MB. Split it into smaller files.',
        ]);

        $import = $runner->upload(ImportKind::from($data['kind']), $request->file('file'), $request->user());

        return to_route('imports.show', [$import, ...(($data['setup'] ?? false) ? ['setup' => 1] : [])]);
    }

    public function show(Request $request, Import $import): Response
    {
        $import->loadMissing('creator:id,name');

        return Inertia::render('settings/import', [
            'import' => [
                ...$this->summary($import),
                'columns' => array_map(fn (Column $c) => $c->key, $import->kind->columns()),
                'errors' => array_slice($import->errors ?? [], 0, 200),
                'error_count' => count($import->errors ?? []),
                'preview' => $import->preview ?? [],
            ],
            'fromSetup' => $request->boolean('setup'),
        ]);
    }

    public function run(Request $request, Import $import, ImportRunner $runner): RedirectResponse
    {
        $data = $request->validate(['leave_out_errors' => ['boolean'], 'setup' => ['boolean']]);

        $runner->import($import, $request->user(), (bool) ($data['leave_out_errors'] ?? false));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$import->kind->label()} imported."]);

        return to_route('imports.show', [$import, ...(($data['setup'] ?? false) ? ['setup' => 1] : [])]);
    }

    public function errors(Import $import, ImportRunner $runner): HttpResponse
    {
        return response($runner->errorReport($import), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.pathinfo($import->filename, PATHINFO_FILENAME).'-errors.csv"',
        ]);
    }

    public function destroy(Request $request, Import $import): RedirectResponse
    {
        if ($import->status === Import::CHECKED) {
            $import->forceFill(['status' => Import::DISCARDED])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Upload discarded. Nothing was imported.']);

        return to_route('imports.index', ['kind' => $import->kind->value, ...($request->boolean('setup') ? ['setup' => 1] : [])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Import $import): array
    {
        return [
            'id' => $import->id,
            'kind' => $import->kind->value,
            'kind_label' => $import->kind->label(),
            'filename' => $import->filename,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'error_rows' => $import->error_rows,
            'created_count' => $import->created_count,
            'updated_count' => $import->updated_count,
            'skipped_count' => $import->skipped_count,
            'by' => $import->creator?->name,
            'created_at' => $import->created_at->toIso8601String(),
            'imported_at' => $import->imported_at?->toIso8601String(),
            'file_kept' => Files::disk()->exists($import->path),
        ];
    }
}
