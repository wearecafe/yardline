<?php

namespace App\Http\Controllers;

use App\Access\YardScope;
use App\Models\Location;
use App\Purchasing\TransferStock;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockTransferController extends Controller
{
    public function store(Request $request, TransferStock $transfer): RedirectResponse
    {
        $data = $request->validate([
            // From a yard they work, to any of the business's yards.
            'from_location_id' => ['required', YardScope::locationRule($request->user())],
            'to_location_id' => ['required', TenantRule::exists('locations')],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', TenantRule::exists('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'lines.*.lot_id' => ['nullable', 'integer'],
        ]);

        $done = $transfer->handle(
            Location::query()->findOrFail((int) $data['from_location_id']),
            Location::query()->findOrFail((int) $data['to_location_id']),
            array_values(array_map(fn ($l) => ['product_id' => (int) $l['product_id'], 'quantity' => (float) $l['quantity'], 'lot_id' => isset($l['lot_id']) ? (int) $l['lot_id'] : null], $data['lines'])),
            $request->user(),
            $data['notes'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Transfer {$done->number} done."]);

        return back();
    }
}
