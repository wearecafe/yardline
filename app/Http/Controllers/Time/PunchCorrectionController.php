<?php

namespace App\Http\Controllers\Time;

use App\Enums\PunchType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Time\Concerns\UsesTime;
use App\Models\Employee;
use App\Models\TimeAdjustment;
use App\Models\TimePunch;
use App\Models\User;
use App\Time\PunchCorrections;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * A manager fixing someone's time. Every change needs a reason; the
 * original punch is kept and the employee is told.
 */
class PunchCorrectionController extends Controller
{
    use UsesTime;

    public function store(Request $request, Employee $employee, PunchCorrections $corrections): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(PunchType::class)],
            'at' => ['required', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $at = $this->local((string) $data['at']);
        $corrections->add($employee, PunchType::from((string) $data['type']), $at, (string) $data['reason'], $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Punch added.']);

        return back();
    }

    public function update(Request $request, TimePunch $punch, PunchCorrections $corrections): RedirectResponse
    {
        $data = $request->validate([
            'at' => ['required', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $corrections->change($punch, $this->local((string) $data['at']), (string) $data['reason'], $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Punch changed.']);

        return back();
    }

    public function destroy(Request $request, TimePunch $punch, PunchCorrections $corrections): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $corrections->remove($punch, (string) $data['reason'], $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Punch removed.']);

        return back();
    }

    public function hours(Request $request, Employee $employee, PunchCorrections $corrections): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'hours' => ['required', 'numeric', 'between:-24,24', 'not_in:0'],
            'overtime' => ['boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $corrections->hours(
            $employee,
            CarbonImmutable::parse((string) $data['date'], $this->tz()),
            (int) round((float) $data['hours'] * 60),
            (bool) ($data['overtime'] ?? false),
            (string) $data['reason'],
            $this->user($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Hours adjusted.']);

        return back();
    }

    public function resolve(TimeAdjustment $adjustment, PunchCorrections $corrections): RedirectResponse
    {
        $corrections->resolve($adjustment);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispute marked as sorted out.']);

        return back();
    }

    private function local(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $this->tz()) ?: CarbonImmutable::parse($value, $this->tz());
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
