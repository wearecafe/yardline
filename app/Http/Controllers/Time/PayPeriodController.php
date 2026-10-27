<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Controller;
use App\Models\PayPeriod;
use App\Models\User;
use App\Time\PayrollExporter;
use App\Time\Timesheets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closing a pay period and sending its hours to payroll.
 */
class PayPeriodController extends Controller
{
    public function lock(Request $request, PayPeriod $period, Timesheets $timesheets): RedirectResponse
    {
        $timesheets->lock($period, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Locked {$period->label()}. It’s ready to export."]);

        return back();
    }

    public function unlock(PayPeriod $period, Timesheets $timesheets): RedirectResponse
    {
        $timesheets->unlock($period);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$period->label()} is open for changes again."]);

        return back();
    }

    /**
     * Downloads the hours summary or the ADP import.
     */
    public function download(Request $request, PayPeriod $period, string $format, PayrollExporter $exporter): Response
    {
        abort_unless(in_array($format, ['summary', 'adp'], true), 404);

        $file = $format === 'adp' ? $exporter->adp($period, $this->user($request)) : $exporter->summary($period, $this->user($request));

        return response($file['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    public function quickbooks(Request $request, PayPeriod $period, PayrollExporter $exporter): RedirectResponse
    {
        $export = $exporter->quickbooks($period, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent {$export->employees} people’s hours to QuickBooks."]);

        return back();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
