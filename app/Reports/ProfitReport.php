<?php

namespace App\Reports;

use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\MaintenanceRecord;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PalletMovement;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\RentalPayment;
use App\Models\RentalUnit;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The owner's profit and loss, down to gross profit: revenue from goods and
 * rentals, less the direct costs of earning it. Operating expenses (wages,
 * rent, fuel) live in QuickBooks, so net profit stays there.
 *
 * Accrual, by the date that syncs to QuickBooks: goods on the invoice date,
 * credit notes on theirs, rentals when the equipment went out. Amounts are
 * before tax; deposits are never revenue.
 *
 * Goods cost what was fixed on each line when it was invoiced (see
 * RecordLineCosts). Returns put back on the shelf reverse that cost;
 * shrinkage, damage and count differences are valued at the yard's average
 * cost when they happened.
 */
class ProfitReport
{
    /**
     * Statement lines in order: key => [label, group]. Costs are positive
     * when they cost the yard money.
     */
    public const LINES = [
        'goods_sales' => ['Goods sales', 'goods_revenue'],
        'delivery_fees' => ['Delivery fees', 'goods_revenue'],
        'restocking_fees' => ['Restocking fees', 'goods_revenue'],
        'returns' => ['Returns and credits', 'goods_revenue'],
        'rental_charges' => ['Rental charges', 'rental_revenue'],
        'damage_waiver' => ['Damage waiver', 'rental_revenue'],
        'rental_delivery' => ['Delivery and pickup', 'rental_revenue'],
        'rental_extras' => ['Damage, late, fuel and cleaning', 'rental_revenue'],
        'cogs' => ['Cost of goods sold', 'costs'],
        'returned_to_stock' => ['Returned to stock', 'costs'],
        'adjustments' => ['Inventory adjustments', 'costs'],
        'maintenance' => ['Rental maintenance and repairs', 'costs'],
        'card_fees' => ['Card processing fees', 'costs'],
    ];

    /** What a goods line cost, in cents. Null when it has no cost. */
    private const LINE_COST = 'ROUND(COALESCE(order_lines.base_quantity, order_lines.quantity * order_lines.unit_factor) * order_lines.unit_cost_cents)';

    /** How far below its list price a goods line sold, in cents. */
    private const LINE_DISCOUNT = 'CASE WHEN order_lines.list_price_cents > order_lines.unit_price_cents THEN ROUND((order_lines.list_price_cents - order_lines.unit_price_cents) * order_lines.quantity) ELSE 0 END';

    /**
     * @return array{
     *     lines: array<string, int>,
     *     goods: array{revenue: int, costs: int, profit: int, margin: float|null},
     *     rentals: array{revenue: int, costs: int, profit: int, margin: float|null},
     *     net_revenue: int,
     *     direct_costs: int,
     *     gross_profit: int,
     *     margin: float|null,
     * }
     */
    public function summary(ProfitFilters $f): array
    {
        $lines = array_fill_keys(array_keys(self::LINES), 0);
        $goodsFees = 0;
        $rentalFees = 0;

        if ($f->includesGoods()) {
            $sales = $this->sums($this->goodsLines($f), [
                'sales' => 'SUM(order_lines.line_total_cents)',
                'cost' => 'SUM('.self::LINE_COST.')',
            ]);
            $credits = $this->sums($this->credits($f), [
                'returned' => 'SUM(credit_notes.subtotal_cents)',
                'fees' => 'SUM(credit_notes.restocking_fee_cents)',
            ]);

            $lines['goods_sales'] = $sales['sales'];
            $lines['cogs'] = $sales['cost'];
            $lines['delivery_fees'] = $this->sums($this->invoicedOrders($f), ['fees' => 'SUM(orders.delivery_fee_cents)'])['fees'];
            $lines['returns'] = -$credits['returned'];
            $lines['restocking_fees'] = $credits['fees'];
            $lines['returned_to_stock'] = -$this->sums($this->restocked($f), ['cost' => 'SUM(ROUND(credit_note_lines.base_quantity * credit_note_lines.unit_cost_cents))'])['cost'];
            $goodsFees = $this->sums($this->goodsPayments($f), ['fees' => 'SUM(payments.fee_cents)'])['fees'];

            // Stock adjustments aren't from any one sales channel.
            if ($f->channel === null) {
                $lines['adjustments'] = -$this->sums($this->adjustments($f), ['cost' => 'SUM(ROUND(stock_movements.quantity * stock_movements.unit_cost_cents))'])['cost'];
            }
        }

        if ($f->includesRentals()) {
            $rentals = $this->sums($this->rentals($f), [
                'charges' => 'SUM(rentals.rental_cents)',
                'waiver' => 'SUM(rentals.waiver_cents)',
                'delivery' => 'SUM(rentals.delivery_fee_cents)',
                'extras' => 'SUM(rentals.charges_cents)',
            ]);

            $lines['rental_charges'] = $rentals['charges'];
            $lines['damage_waiver'] = $rentals['waiver'];
            $lines['rental_delivery'] = $rentals['delivery'];
            $lines['rental_extras'] = $rentals['extras'];
            $lines['maintenance'] = $this->sums($this->maintenance($f), ['cost' => 'SUM(maintenance_records.cost_cents)'])['cost'];
            $rentalFees = $this->sums($this->rentalPayments($f), ['fees' => 'SUM(rental_payments.fee_cents)'])['fees'];
        }

        $lines['card_fees'] = $goodsFees + $rentalFees;

        $goodsRevenue = $lines['goods_sales'] + $lines['delivery_fees'] + $lines['restocking_fees'] + $lines['returns'];
        $goodsCosts = $lines['cogs'] + $lines['returned_to_stock'] + $lines['adjustments'] + $goodsFees;
        $rentalRevenue = $lines['rental_charges'] + $lines['damage_waiver'] + $lines['rental_delivery'] + $lines['rental_extras'];
        $rentalCosts = $lines['maintenance'] + $rentalFees;
        $revenue = $goodsRevenue + $rentalRevenue;
        $costs = $goodsCosts + $rentalCosts;

        return [
            'lines' => $lines,
            'goods' => $this->segment($goodsRevenue, $goodsCosts),
            'rentals' => $this->segment($rentalRevenue, $rentalCosts),
            'net_revenue' => $revenue,
            'direct_costs' => $costs,
            'gross_profit' => $revenue - $costs,
            'margin' => self::margin($revenue - $costs, $revenue),
        ];
    }

    /**
     * Net revenue and gross profit for each month up to the filters' last.
     *
     * @return list<array{month: string, label: string, net_revenue: int, gross_profit: int, margin: float|null}>
     */
    public function trend(ProfitFilters $f, int $months = 6): array
    {
        $rows = [];
        $last = $f->to->startOfMonth();

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $last->subMonthsNoOverflow($i);
            $summary = $this->summary($f->between($start, $start->endOfMonth()));

            $rows[] = [
                'month' => $start->format('Y-m'),
                'label' => $start->format('M'),
                'net_revenue' => $summary['net_revenue'],
                'gross_profit' => $summary['gross_profit'],
                'margin' => $summary['margin'],
            ];
        }

        return $rows;
    }

    /**
     * Goods sales by product category, before returns and adjustments.
     *
     * @return list<array{category: string, sales: int, cost: int, profit: int, margin: float|null, missing: int}>
     */
    public function categories(ProfitFilters $f): array
    {
        if (! $f->includesGoods()) {
            return [];
        }

        $rows = $this->goodsLines($f)
            ->leftJoin('products', 'products.id', '=', 'order_lines.product_id')
            ->toBase()
            ->groupBy('products.category')
            ->selectRaw('products.category as category')
            ->selectRaw('COALESCE(SUM(order_lines.line_total_cents), 0) as sales')
            ->selectRaw('COALESCE(SUM('.self::LINE_COST.'), 0) as cost')
            ->selectRaw("SUM(CASE WHEN order_lines.cost_source = 'missing' THEN 1 ELSE 0 END) as missing")
            ->get();

        return $this->ranked($rows->map(fn ($row) => [
            'category' => filled($row->category ?? null) ? (string) $row->category : 'Uncategorized',
            ...$this->profitRow((int) $row->sales, (int) $row->cost),
            'missing' => (int) $row->missing,
        ])->all());
    }

    /**
     * Goods sales by product, before returns, most profitable first.
     *
     * @return list<array{product_id: int|null, sku: string|null, name: string, category: string|null, quantity: float, unit: string|null, sales: int, cost: int, profit: int, margin: float|null, missing: int}>
     */
    public function products(ProfitFilters $f, int $limit = 200): array
    {
        if (! $f->includesGoods()) {
            return [];
        }

        $rows = $this->goodsLines($f)
            ->leftJoin('products', 'products.id', '=', 'order_lines.product_id')
            ->toBase()
            ->groupBy('order_lines.product_id')
            ->selectRaw('order_lines.product_id as product_id')
            ->selectRaw('MAX(products.sku) as sku, MAX(products.name) as name, MAX(products.category) as category, MAX(products.unit) as unit')
            ->selectRaw('SUM(COALESCE(order_lines.base_quantity, order_lines.quantity * order_lines.unit_factor)) as quantity')
            ->selectRaw('COALESCE(SUM(order_lines.line_total_cents), 0) as sales')
            ->selectRaw('COALESCE(SUM('.self::LINE_COST.'), 0) as cost')
            ->selectRaw("SUM(CASE WHEN order_lines.cost_source = 'missing' THEN 1 ELSE 0 END) as missing")
            ->get();

        $ranked = $this->ranked($rows->map(fn ($row) => [
            'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
            'sku' => $row->sku !== null ? (string) $row->sku : null,
            'name' => $row->name !== null ? (string) $row->name : 'Lines with no product',
            'category' => $row->category !== null ? (string) $row->category : null,
            'quantity' => round((float) $row->quantity, 3),
            'unit' => $row->unit !== null ? (string) $row->unit : null,
            ...$this->profitRow((int) $row->sales, (int) $row->cost),
            'missing' => (int) $row->missing,
        ])->all());

        return array_slice($ranked, 0, $limit);
    }

    /**
     * Goods sales by account, before returns, with each account's price
     * list: which contractors are profitable after their discounts.
     *
     * @return list<array{organization_id: int|null, name: string, price_list: string|null, orders: int, sales: int, cost: int, profit: int, margin: float|null, discounts: int}>
     */
    public function customers(ProfitFilters $f, int $limit = 200): array
    {
        if (! $f->includesGoods()) {
            return [];
        }

        $rows = $this->goodsLines($f)
            ->leftJoin('organizations', 'organizations.id', '=', 'orders.organization_id')
            ->leftJoin('price_lists', 'price_lists.id', '=', 'organizations.price_list_id')
            ->toBase()
            ->groupBy('orders.organization_id')
            ->selectRaw('orders.organization_id as organization_id')
            ->selectRaw('MAX(organizations.name) as name, MAX(price_lists.name) as price_list')
            ->selectRaw('COUNT(DISTINCT orders.id) as orders')
            ->selectRaw('COALESCE(SUM(order_lines.line_total_cents), 0) as sales')
            ->selectRaw('COALESCE(SUM('.self::LINE_COST.'), 0) as cost')
            ->selectRaw('COALESCE(SUM('.self::LINE_DISCOUNT.'), 0) as discounts')
            ->get();

        $ranked = $this->ranked($rows->map(fn ($row) => [
            'organization_id' => $row->organization_id !== null ? (int) $row->organization_id : null,
            'name' => $row->organization_id !== null ? (string) $row->name : 'Walk-in customers',
            'price_list' => $row->price_list !== null ? (string) $row->price_list : null,
            'orders' => (int) $row->orders,
            ...$this->profitRow((int) $row->sales, (int) $row->cost),
            'discounts' => (int) $row->discounts,
        ])->all());

        return array_slice($ranked, 0, $limit);
    }

    /**
     * Each rental unit's rental charges, maintenance and what's left, with
     * how many rentals it went out on. Waiver, delivery and extra charges
     * belong to the rental, not a unit, so they aren't split out here.
     *
     * @return list<array{unit_id: int, serial: string, name: string, rentals: int, revenue: int, maintenance: int, profit: int}>
     */
    public function rentalUnits(ProfitFilters $f): array
    {
        if (! $f->includesRentals()) {
            return [];
        }

        $revenue = RentalItem::query()
            ->whereIn('rental_items.rental_id', $this->rentals($f)->select('rentals.id'))
            ->whereNotNull('rental_items.rental_unit_id')
            ->toBase()
            ->groupBy('rental_items.rental_unit_id')
            ->selectRaw('rental_items.rental_unit_id as unit_id, COUNT(DISTINCT rental_items.rental_id) as rentals, COALESCE(SUM(rental_items.charge_cents), 0) as revenue')
            ->get()
            ->keyBy('unit_id');

        $maintenance = $this->maintenance($f)
            ->toBase()
            ->groupBy('maintenance_records.rental_unit_id')
            ->selectRaw('maintenance_records.rental_unit_id as unit_id, COALESCE(SUM(maintenance_records.cost_cents), 0) as cost')
            ->pluck('cost', 'unit_id');

        $ids = $revenue->keys()->merge($maintenance->keys())->map(fn ($id) => (int) $id)->unique();

        $rows = RentalUnit::query()
            ->with('product:id,name')
            ->whereIn('id', $ids)
            ->get()
            ->map(function (RentalUnit $unit) use ($revenue, $maintenance) {
                $row = $revenue->get($unit->id);
                $charges = (int) ($row->revenue ?? 0);
                $cost = (int) $maintenance->get($unit->id, 0);

                return [
                    'unit_id' => $unit->id,
                    'serial' => $unit->serial,
                    'name' => $unit->product->name ?? 'Rental unit',
                    'rentals' => (int) ($row->rentals ?? 0),
                    'revenue' => $charges,
                    'maintenance' => $cost,
                    'profit' => $charges - $cost,
                ];
            })
            ->sortByDesc('profit')
            ->all();

        return array_values($rows);
    }

    /**
     * Context under the statement: discounts given, deposits held at the
     * end of the period, and goods lines with no cost.
     *
     * @return array{discounts: int, rule_discounts: int, manual_discounts: int, exempt_discounts: int, deposits_held: int, missing_lines: int, missing_revenue: int}
     */
    public function memo(ProfitFilters $f): array
    {
        $memo = ['discounts' => 0, 'rule_discounts' => 0, 'manual_discounts' => 0, 'exempt_discounts' => 0, 'deposits_held' => 0, 'missing_lines' => 0, 'missing_revenue' => 0];

        if (! $f->includesGoods()) {
            return $memo;
        }

        $discounts = $this->sums($this->goodsLines($f), [
            'rules' => 'SUM(CASE WHEN order_lines.price_overridden THEN 0 ELSE '.self::LINE_DISCOUNT.' END)',
            'manual' => 'SUM(CASE WHEN order_lines.price_overridden THEN '.self::LINE_DISCOUNT.' ELSE 0 END)',
            'exempt' => "SUM(CASE WHEN order_lines.price_overridden AND order_lines.price_source = 'exempt' THEN ".self::LINE_DISCOUNT.' ELSE 0 END)',
        ]);
        $missing = $this->sums($this->goodsLines($f)->where('order_lines.cost_source', 'missing'), [
            'lines' => 'COUNT(*)',
            'revenue' => 'SUM(order_lines.line_total_cents)',
        ]);

        $deposits = PalletMovement::query()
            ->where('pallet_movements.created_at', '<', $f->endsBefore())
            ->when($f->locationId, fn ($q, $id) => $q->where('pallet_movements.location_id', $id));

        return [
            'discounts' => $discounts['rules'] + $discounts['manual'],
            'rule_discounts' => $discounts['rules'],
            'manual_discounts' => $discounts['manual'],
            'exempt_discounts' => $discounts['exempt'],
            'deposits_held' => $this->sums($deposits, ['held' => 'SUM(pallet_movements.deposit_cents)'])['held'],
            'missing_lines' => $missing['lines'],
            'missing_revenue' => $missing['revenue'],
        ];
    }

    /**
     * The records behind one statement line, newest first.
     *
     * @return list<array{date: string, reference: string, who: string|null, description: string, amount: int, href: string|null}>
     */
    public function detail(string $key, ProfitFilters $f, int $limit = 200): array
    {
        $goods = $f->includesGoods();
        $rentals = $f->includesRentals();

        $rows = match ($key) {
            'goods_sales', 'cogs', 'missing' => $goods ? $this->goodsDetail($key, $f, $limit) : [],
            'delivery_fees' => $goods ? $this->deliveryDetail($f, $limit) : [],
            'returns', 'restocking_fees' => $goods ? $this->creditDetail($key, $f, $limit) : [],
            'returned_to_stock' => $goods ? $this->restockDetail($f, $limit) : [],
            'adjustments' => $goods && $f->channel === null ? $this->adjustmentDetail($f, $limit) : [],
            'rental_charges', 'damage_waiver', 'rental_delivery', 'rental_extras' => $rentals ? $this->rentalDetail($key, $f, $limit) : [],
            'maintenance' => $rentals ? $this->maintenanceDetail($f, $limit) : [],
            'card_fees' => $this->feeDetail($f, $limit),
            default => [],
        };

        usort($rows, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return array_slice($rows, 0, $limit);
    }

    public static function margin(int $profit, int $revenue): ?float
    {
        return $revenue !== 0 ? round($profit / $revenue * 100, 1) : null;
    }

    // ------------------------------------------------------------- queries

    /**
     * Goods lines on invoices dated in the period.
     *
     * @return Builder<OrderLine>
     */
    public function goodsLines(ProfitFilters $f): Builder
    {
        return $this->onInvoices(OrderLine::query()->join('orders', 'orders.id', '=', 'order_lines.order_id'), $f);
    }

    /**
     * @return Builder<Order>
     */
    private function invoicedOrders(ProfitFilters $f): Builder
    {
        return $this->onInvoices(Order::query(), $f);
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query  joined to orders
     * @return Builder<T>
     */
    private function onInvoices(Builder $query, ProfitFilters $f): Builder
    {
        return $query
            ->join('invoices', 'invoices.order_id', '=', 'orders.id')
            ->where('orders.type', 'sale')
            ->where('orders.status', '!=', 'cancelled')
            ->where('invoices.status', '!=', 'void')
            ->whereBetween('invoices.issued_on', [$f->fromDate(), $f->toDate()])
            ->when($f->locationId, fn ($q, $id) => $q->where('orders.location_id', $id))
            ->when($f->channel, fn ($q, $channel) => $q->where('orders.source', $channel));
    }

    /**
     * Credit notes for returned goods and price adjustments. Pallet returns
     * only give back deposits, which were never revenue.
     *
     * @return Builder<CreditNote>
     */
    private function credits(ProfitFilters $f): Builder
    {
        return CreditNote::query()
            ->whereIn('credit_notes.kind', ['return', 'adjustment'])
            ->whereBetween('credit_notes.issued_on', [$f->fromDate(), $f->toDate()])
            ->when($f->locationId, fn ($q, $id) => $q->where('credit_notes.location_id', $id))
            ->when($f->channel, fn ($q, $channel) => $q->whereIn('credit_notes.order_id', Order::query()->select('id')->where('source', $channel)));
    }

    /**
     * @return Builder<CreditNoteLine>
     */
    private function restocked(ProfitFilters $f): Builder
    {
        return CreditNoteLine::query()
            ->where('credit_note_lines.restock', true)
            ->whereNotNull('credit_note_lines.unit_cost_cents')
            ->whereIn('credit_note_lines.credit_note_id', $this->credits($f)->select('credit_notes.id'));
    }

    /**
     * Shrinkage, damage and count differences.
     *
     * @return Builder<StockMovement>
     */
    private function adjustments(ProfitFilters $f): Builder
    {
        return StockMovement::query()
            ->whereIn('stock_movements.type', ['adjustment', 'count', 'damage'])
            ->where('stock_movements.created_at', '>=', $f->startsAt())
            ->where('stock_movements.created_at', '<', $f->endsBefore())
            ->when($f->locationId, fn ($q, $id) => $q->where('stock_movements.location_id', $id));
    }

    /**
     * Rentals that went out in the period: their invoice date.
     *
     * @return Builder<Rental>
     */
    private function rentals(ProfitFilters $f): Builder
    {
        return Rental::query()
            ->where('rentals.status', '!=', 'cancelled')
            ->where('rentals.checked_out_at', '>=', $f->startsAt())
            ->where('rentals.checked_out_at', '<', $f->endsBefore())
            ->when($f->locationId, fn ($q, $id) => $q->where('rentals.location_id', $id));
    }

    /**
     * Service and repairs finished in the period.
     *
     * @return Builder<MaintenanceRecord>
     */
    private function maintenance(ProfitFilters $f): Builder
    {
        return MaintenanceRecord::query()
            ->where('maintenance_records.status', 'done')
            ->where('maintenance_records.completed_at', '>=', $f->startsAt())
            ->where('maintenance_records.completed_at', '<', $f->endsBefore())
            ->when($f->locationId, fn ($q, $id) => $q->whereIn('maintenance_records.rental_unit_id', RentalUnit::query()->select('id')->where('location_id', $id)));
    }

    /**
     * Card payments on goods orders, by when they were taken.
     *
     * @return Builder<Payment>
     */
    private function goodsPayments(ProfitFilters $f): Builder
    {
        return Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.type', 'sale')
            ->where('payments.status', 'succeeded')
            ->whereNotNull('payments.fee_cents')
            ->where('payments.succeeded_at', '>=', $f->startsAt())
            ->where('payments.succeeded_at', '<', $f->endsBefore())
            ->when($f->locationId, fn ($q, $id) => $q->where('orders.location_id', $id))
            ->when($f->channel, fn ($q, $channel) => $q->where('orders.source', $channel));
    }

    /**
     * @return Builder<RentalPayment>
     */
    private function rentalPayments(ProfitFilters $f): Builder
    {
        return RentalPayment::query()
            ->join('rentals', 'rentals.id', '=', 'rental_payments.rental_id')
            ->where('rental_payments.status', 'succeeded')
            ->whereNotNull('rental_payments.fee_cents')
            ->where('rental_payments.succeeded_at', '>=', $f->startsAt())
            ->where('rental_payments.succeeded_at', '<', $f->endsBefore())
            ->when($f->locationId, fn ($q, $id) => $q->where('rentals.location_id', $id));
    }

    // -------------------------------------------------------------- detail

    /**
     * @return list<array{date: string, reference: string, who: string|null, description: string, amount: int, href: string|null}>
     */
    private function goodsDetail(string $key, ProfitFilters $f, int $limit): array
    {
        $query = $this->goodsLines($f)
            ->select('order_lines.*', 'invoices.issued_on as invoice_date', 'invoices.number as invoice_number')
            ->with(['order.organization:id,name', 'order.customer:id,first_name,last_name'])
            ->orderByDesc('invoices.issued_on')
            ->orderByDesc('order_lines.id')
            ->limit($limit);

        if ($key === 'missing') {
            $query->where('order_lines.cost_source', 'missing');
        } elseif ($key === 'cogs') {
            $query->whereNotNull('order_lines.unit_cost_cents');
        }

        return array_values($query->get()->map(fn (OrderLine $line) => [
            'date' => (string) $line->getAttribute('invoice_date'),
            'reference' => (string) $line->getAttribute('invoice_number'),
            'who' => $this->who($line->order),
            'description' => $line->description.' · '.rtrim(rtrim((string) $line->quantity, '0'), '.').' '.$line->unit,
            'amount' => $key === 'cogs' ? (int) $line->costCents() : $line->line_total_cents,
            'href' => route('orders.show', $line->order_id),
        ])->all());
    }

    /**
     * @return list<array{date: string, reference: string, who: string|null, description: string, amount: int, href: string|null}>
     */
    private function deliveryDetail(ProfitFilters $f, int $limit): array
    {
        return array_values($this->invoicedOrders($f)
            ->where('orders.delivery_fee_cents', '>', 0)
            ->select('orders.*', 'invoices.issued_on as invoice_date', 'invoices.number as invoice_number')
            ->with(['organization:id,name', 'customer:id,first_name,last_name'])
            ->orderByDesc('invoices.issued_on')
            ->limit($limit)
            ->get()
            ->map(fn (Order $order) => [
                'date' => (string) $order->getAttribute('invoice_date'),
                'reference' => (string) $order->getAttribute('invoice_number'),
                'who' => $this->who($order),
                'description' => "Delivery on {$order->number}",
                'amount' => (int) $order->delivery_fee_cents,
                'href' => route('orders.show', $order),
            ])->all());
    }

    /**
     * @return list<array{date: string, reference: string, who: string|null, description: string, amount: int, href: string|null}>
     */
    private function creditDetail(string $key, ProfitFilters $f, int $limit): array
    {
        return array_values($this->credits($f)
            ->when($key === 'restocking_fees', fn ($q) => $q->where('restocking_fee_cents', '>', 0))
            ->with(['organization:id,name', 'customer:id,first_name,last_name'])
            ->orderByDesc('issued_on')
            ->limit($limit)
            ->get()
            ->map(fn (CreditNote $credit) => [
                'date' => $credit->issued_on->toDateString(),
                'reference' => $credit->number,
                'who' => $credit->organization->name ?? $credit->customer->full_name ?? null,
                'description' => $key === 'restocking_fees' ? 'Restocking fee' : ($credit->reason ?: ucfirst($credit->kind)),
                'amount' => $key === 'restocking_fees' ? $credit->restocking_fee_cents : -$credit->subtotal_cents,
                'href' => route('credit-notes.show', $credit),
            ])->all());
    }

    /**
     * @return list<array{date: string, reference: string, who: string|null, description: string, amount: int, href: string|null}>
     */
    private function restockDetail(ProfitFilters $f, int $limit): array
    {
        return array_values($this->restocked($f)
            ->with('creditNote')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (CreditNoteLine $line) => [
                'date' => $line->creditNote->issued_on->toDateString(),
                'reference' => $line->creditNote->number,
                'who' => null,
                'description' => $line->description,
                'amount' => -(int) round((float) $line->base_quantity * (float) $line->unit_cost_cents),
                'href' => route('credit-notes.show', $line->credit_note_id),
            ])->all());
    }

    /**
     * @return list<array{date: string, reference: string, who: string|null, description: string, amount: int, href: string|null}>
     */
    private function adjustmentDetail(ProfitFilters $f, int $limit): array
    {
        return array_values($this->adjustments($f)
            ->with(['product:id,sku,name', 'location:id,name'])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StockMovement $movement) => [
                'date' => $movement->created_at->setTimezone($f->timezone)->toDateString(),
                'reference' => ucfirst($movement->type->value),
                'who' => $movement->location->name,
                'description' => trim(($movement->product->name ?? 'Product').' · '.rtrim(rtrim((string) $movement->quantity, '0'), '.').($movement->note ? " · {$movement->note}" : '')),
                'amount' => -(int) round((float) $movement->quantity * (float) ($movement->unit_cost_cents ?? 0)),
                'href' => route('inventory.index'),
            ])->all());
    }

    /**
     * @return list<array{date: string, reference: string, who: string|null, description: string, amount: int, href: string|null}>
     */
    private function rentalDetail(string $key, ProfitFilters $f, int $limit): array
    {
        $column = match ($key) {
            'damage_waiver' => 'waiver_cents',
            'rental_delivery' => 'delivery_fee_cents',
            'rental_extras' => 'charges_cents',
            default => 'rental_cents',
        };

        return array_values($this->rentals($f)
            ->where($column, '>', 0)
            ->with(['organization:id,name', 'customer:id,first_name,last_name'])
            ->latest('checked_out_at')
            ->limit($limit)
            ->get()
            ->map(fn (Rental $rental) => [
                'date' => $rental->checked_out_at?->setTimezone($f->timezone)->toDateString() ?? '',
                'reference' => $rental->number,
                'who' => $rental->customerName(),
                'description' => self::LINES[$key][0],
                'amount' => (int) $rental->getAttribute($column),
                'href' => route('rentals.show', $rental),
            ])->all());
    }

    /**
     * @return list<array{date: string, reference: string, who: string|null, description: string, amount: int, href: string|null}>
     */
    private function maintenanceDetail(ProfitFilters $f, int $limit): array
    {
        return array_values($this->maintenance($f)
            ->where('cost_cents', '>', 0)
            ->with('unit.product:id,name')
            ->latest('completed_at')
            ->limit($limit)
            ->get()
            ->map(fn (MaintenanceRecord $record) => [
                'date' => $record->completed_at?->setTimezone($f->timezone)->toDateString() ?? '',
                'reference' => $record->unit->serial,
                'who' => $record->unit->product->name ?? null,
                'description' => ucfirst($record->kind->value).': '.$record->description,
                'amount' => $record->cost_cents,
                'href' => route('fleet.units.show', $record->rental_unit_id),
            ])->all());
    }

    /**
     * @return list<array{date: string, reference: string, who: string|null, description: string, amount: int, href: string|null}>
     */
    private function feeDetail(ProfitFilters $f, int $limit): array
    {
        $rows = [];

        if ($f->includesGoods()) {
            foreach ($this->goodsPayments($f)->select('payments.*', 'orders.number as order_number')->latest('payments.succeeded_at')->limit($limit)->get() as $payment) {
                $rows[] = [
                    'date' => $payment->succeeded_at?->setTimezone($f->timezone)->toDateString() ?? '',
                    'reference' => (string) $payment->getAttribute('order_number'),
                    'who' => null,
                    'description' => $payment->tender->label().' of '.number_format($payment->amount_cents / 100, 2),
                    'amount' => (int) $payment->fee_cents,
                    'href' => route('orders.show', $payment->order_id),
                ];
            }
        }

        if ($f->includesRentals()) {
            foreach ($this->rentalPayments($f)->select('rental_payments.*', 'rentals.number as rental_number')->latest('rental_payments.succeeded_at')->limit($limit)->get() as $payment) {
                $rows[] = [
                    'date' => $payment->succeeded_at?->setTimezone($f->timezone)->toDateString() ?? '',
                    'reference' => (string) $payment->getAttribute('rental_number'),
                    'who' => null,
                    'description' => $payment->tender->label().' of '.number_format($payment->amount_cents / 100, 2),
                    'amount' => (int) $payment->fee_cents,
                    'href' => route('rentals.show', $payment->rental_id),
                ];
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------- helpers

    /**
     * Run aggregate expressions on a query, as whole cents (0 when empty).
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  array<string, string>  $expressions  alias => SQL aggregate
     * @return array<string, int>
     */
    private function sums(Builder $query, array $expressions): array
    {
        $base = $query->toBase();
        $base->columns = null;

        foreach ($expressions as $alias => $sql) {
            $base->selectRaw("COALESCE({$sql}, 0) as {$alias}");
        }

        $row = (array) $base->first();
        $sums = [];

        foreach (array_keys($expressions) as $alias) {
            $sums[$alias] = (int) round((float) ($row[$alias] ?? 0));
        }

        return $sums;
    }

    /**
     * @return array{sales: int, cost: int, profit: int, margin: float|null}
     */
    private function profitRow(int $sales, int $cost): array
    {
        return ['sales' => $sales, 'cost' => $cost, 'profit' => $sales - $cost, 'margin' => self::margin($sales - $cost, $sales)];
    }

    /**
     * @return array{revenue: int, costs: int, profit: int, margin: float|null}
     */
    private function segment(int $revenue, int $costs): array
    {
        return ['revenue' => $revenue, 'costs' => $costs, 'profit' => $revenue - $costs, 'margin' => self::margin($revenue - $costs, $revenue)];
    }

    /**
     * Most profitable first.
     *
     * @template R of array{profit: int}
     *
     * @param  array<array-key, R>  $rows
     * @return list<R>
     */
    private function ranked(array $rows): array
    {
        usort($rows, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return array_values($rows);
    }

    private function who(?Order $order): ?string
    {
        return $order?->organization->name ?? $order?->customer->full_name ?? null;
    }
}
