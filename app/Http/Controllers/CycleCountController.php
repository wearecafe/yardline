<?php

namespace App\Http\Controllers;

use App\Access\YardScope;
use App\Models\CycleCount;
use App\Models\Location;
use App\Purchasing\CycleCounting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CycleCountController extends Controller
{
    public function store(Request $request, CycleCounting $counting): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', YardScope::locationRule($request->user())],
            'bin' => ['nullable', 'string', 'max:40'],
            'category' => ['nullable', 'string', 'max:100'],
        ]);

        $count = $counting->start(Location::query()->findOrFail((int) $data['location_id']), $request->user(), $data['bin'] ?? null, $data['category'] ?? null);

        return to_route('cycle-counts.show', $count);
    }

    public function show(CycleCount $cycleCount): Response
    {
        $cycleCount->load(['lines.product:id,sku,name,unit', 'location:id,name', 'starter:id,name']);

        return Inertia::render('inventory/count', [
            'count' => [
                'id' => $cycleCount->id,
                'name' => $cycleCount->name,
                'status' => $cycleCount->status,
                'started_by' => $cycleCount->starter?->name,
                'created_at' => $cycleCount->created_at->toIso8601String(),
                'completed_at' => $cycleCount->completed_at?->toIso8601String(),
                'lines' => $cycleCount->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'name' => $l->product->name,
                    'sku' => $l->product->sku,
                    'unit' => $l->product->unit->value,
                    'bin' => $l->bin,
                    'expected' => $l->expected,
                    'counted' => $l->counted,
                ])->values(),
            ],
        ]);
    }

    public function update(Request $request, CycleCount $cycleCount): RedirectResponse
    {
        $data = $request->validate([
            'counts' => ['required', 'array'],
            'counts.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        foreach ($cycleCount->lines as $line) {
            if (array_key_exists((string) $line->id, $data['counts'])) {
                $value = $data['counts'][(string) $line->id];
                $line->update(['counted' => $value === null || $value === '' ? null : $value]);
            }
        }

        return back();
    }

    public function complete(Request $request, CycleCount $cycleCount, CycleCounting $counting): RedirectResponse
    {
        $counting->complete($cycleCount, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Count posted.']);

        return to_route('inventory.index');
    }
}
