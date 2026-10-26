<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sequence;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Notifications\PurchaseOrderMessage;
use App\Orders\OrderRules;
use App\Purchasing\ReceivePurchaseOrder;
use App\Support\Demo;
use App\Support\Money;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchase orders to suppliers, and receiving deliveries against them.
 */
class PurchasingController extends Controller
{
    public function index(): Response
    {
        $levels = StockLevel::query()
            ->with(['product:id,sku,name,unit,cost_cents,supplier_id,supplier_name', 'product.supplier:id,name', 'location:id,name'])
            ->whereNotNull('reorder_point')
            ->whereColumn('on_hand', '<=', 'reorder_point')
            ->get();

        $onOrder = DB::table('purchase_order_lines')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->whereIn('purchase_orders.status', ['draft', 'sent', 'partial'])
            ->where('purchase_orders.tenant_id', auth()->user()?->tenant_id)
            ->groupBy('product_id', 'location_id')
            ->selectRaw('product_id, location_id, sum(quantity - received_quantity) as qty')
            ->get()
            ->keyBy(fn ($r) => "{$r->product_id}:{$r->location_id}");

        return Inertia::render('purchasing/index', [
            'orders' => PurchaseOrder::query()
                ->with(['supplier:id,name', 'location:id,name'])
                ->withCount('lines')
                ->latest('id')
                ->limit(60)
                ->get()
                ->map(fn (PurchaseOrder $po) => [
                    'id' => $po->id,
                    'number' => $po->number,
                    'supplier' => $po->supplier->name,
                    'yard' => $po->location->name,
                    'status' => $po->status,
                    'expected_on' => $po->expected_on?->toDateString(),
                    'total_cents' => $po->total_cents,
                    'lines' => $po->lines_count,
                ]),
            'suggestions' => $levels->map(fn (StockLevel $l) => [
                'product_id' => $l->product_id,
                'location_id' => $l->location_id,
                'sku' => $l->product->sku,
                'name' => $l->product->name,
                'unit' => $l->product->unit->value,
                'yard' => $l->location->name,
                'on_hand' => $l->on_hand,
                'reorder_point' => $l->reorder_point,
                'on_order' => number_format((float) ($onOrder["{$l->product_id}:{$l->location_id}"]->qty ?? 0), 3, '.', ''),
                'supplier' => $l->product->supplier->name ?? $l->product->supplier_name,
                'supplier_id' => $l->product->supplier_id,
            ])->values(),
            'suppliers' => Supplier::query()->orderBy('name')->get(),
            'locations' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', TenantRule::exists('suppliers')],
            'location_id' => ['required', TenantRule::exists('locations')],
            'product_ids' => ['array'],
            'product_ids.*' => ['integer'],
        ]);

        $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);

        $po = DB::transaction(function () use ($data, $supplier, $request) {
            $po = PurchaseOrder::query()->create([
                'number' => 'PO-'.Sequence::next('purchase_orders'),
                'supplier_id' => $supplier->id,
                'location_id' => $data['location_id'],
                'expected_on' => $supplier->lead_days ? now()->addDays($supplier->lead_days)->toDateString() : null,
                'created_by' => $request->user()->id,
            ]);

            // Start from what's below its reorder point.
            foreach (Product::query()->whereIn('id', $data['product_ids'] ?? [])->get() as $i => $product) {
                $level = StockLevel::query()->where('product_id', $product->id)->where('location_id', $data['location_id'])->first();
                $need = $level?->reorder_point !== null ? max(1, (float) $level->reorder_point * 2 - (float) $level->on_hand) : 1;

                $po->lines()->create([
                    'product_id' => $product->id,
                    'quantity' => round($need),
                    'unit_cost_cents' => (int) ($product->cost_cents ?? 0),
                    'position' => $i,
                ]);
            }

            $this->total($po);

            return $po;
        });

        return to_route('purchasing.show', $po);
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load(['supplier', 'location:id,name', 'lines.product:id,sku,name,unit,track_lots,cost_cents']);

        return Inertia::render('purchasing/show', [
            'po' => [
                ...$purchaseOrder->only('id', 'number', 'status', 'supplier_reference', 'notes', 'total_cents', 'location_id', 'supplier_id'),
                'expected_on' => $purchaseOrder->expected_on?->toDateString(),
                'sent_at' => $purchaseOrder->sent_at?->toIso8601String(),
                'received_at' => $purchaseOrder->received_at?->toIso8601String(),
                'supplier' => $purchaseOrder->supplier->only('id', 'name', 'email', 'phone', 'contact_name'),
                'yard' => $purchaseOrder->location->name,
                'lines' => $purchaseOrder->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'product_id' => $l->product_id,
                    'sku' => $l->product->sku,
                    'name' => $l->product->name,
                    'unit' => $l->product->unit->value,
                    'track_lots' => $l->product->track_lots,
                    'quantity' => $l->quantity,
                    'received_quantity' => $l->received_quantity,
                    'outstanding' => number_format($l->outstanding(), 3, '.', ''),
                    'unit_cost_cents' => $l->unit_cost_cents,
                    'current_cost_cents' => $l->product->cost_cents,
                ])->values(),
            ],
            'products' => Product::query()->where('kind', 'sale')->where('track_inventory', true)->orderBy('name')->get(['id', 'sku', 'name', 'unit', 'cost_cents', 'supplier_id']),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if (! in_array($purchaseOrder->status, ['draft', 'sent'], true)) {
            OrderRules::fail('Received purchase orders can’t be changed.');
        }

        $data = $request->validate([
            'location_id' => ['required', TenantRule::exists('locations')],
            'expected_on' => ['nullable', 'date'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['array', 'max:100'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['required', TenantRule::exists('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ]);

        DB::transaction(function () use ($purchaseOrder, $data) {
            $purchaseOrder->update(collect($data)->except('lines')->all());
            $keep = [];

            foreach (array_values($data['lines'] ?? []) as $i => $row) {
                $line = isset($row['id']) ? $purchaseOrder->lines()->find((int) $row['id']) : null;
                $line ??= $purchaseOrder->lines()->make();
                $line->fill([
                    'product_id' => $row['product_id'],
                    'quantity' => $row['quantity'],
                    'unit_cost_cents' => (int) Money::parse($row['unit_cost']),
                    'position' => $i,
                ])->save();
                $keep[] = $line->id;
            }

            $purchaseOrder->lines()->whereNotIn('id', $keep)->delete();
            $this->total($purchaseOrder);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$purchaseOrder->number} saved."]);

        return back();
    }

    public function send(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if ($purchaseOrder->lines()->doesntExist()) {
            OrderRules::fail('Add a line first.');
        }

        $purchaseOrder->load(['supplier', 'location', 'lines.product']);

        if (filled($purchaseOrder->supplier->email) && ! Demo::isCurrentTenant()) {
            Notification::route('mail', (string) $purchaseOrder->supplier->email)->notify(new PurchaseOrderMessage($purchaseOrder));
        }

        $purchaseOrder->forceFill(['status' => $purchaseOrder->status === 'draft' ? 'sent' : $purchaseOrder->status, 'sent_at' => now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => filled($purchaseOrder->supplier->email) ? "Sent to {$purchaseOrder->supplier->email}." : 'Marked as sent.']);

        return back();
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder, ReceivePurchaseOrder $receive): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'lines.*.lot' => ['nullable', 'string', 'max:60'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $receive->handle($purchaseOrder, array_values(array_map(fn ($l) => [
            'line_id' => (int) $l['line_id'],
            'quantity' => (float) ($l['quantity'] ?? 0),
            'lot' => $l['lot'] ?? null,
            'unit_cost_cents' => isset($l['unit_cost']) && $l['unit_cost'] !== '' ? (int) Money::parse($l['unit_cost']) : null,
        ], $data['lines'])), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Received against {$purchaseOrder->number}."]);

        return back();
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if ($purchaseOrder->status === 'received') {
            OrderRules::fail('This purchase order has been received.');
        }

        $purchaseOrder->forceFill(['status' => 'cancelled'])->save();

        return to_route('purchasing.index');
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        Supplier::query()->create($request->validate([
            'name' => ['required', 'string', 'max:255', TenantRule::unique('suppliers', 'name')],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'lead_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]));

        return back();
    }

    private function total(PurchaseOrder $po): void
    {
        $po->forceFill(['total_cents' => (int) $po->lines()->get()->sum(fn ($l) => (int) round((float) $l->quantity * $l->unit_cost_cents))])->save();
    }
}
