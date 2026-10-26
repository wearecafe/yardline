<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\StockLevel;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $today = now()->startOfDay();

        return Inertia::render('dashboard', [
            'stats' => [
                'drafts' => Order::query()->where('status', OrderStatus::Draft->value)->count(),
                'to_release' => Order::query()->where('status', OrderStatus::Confirmed->value)->count(),
                'rentals_out' => Order::query()->where('type', 'rental')->where('status', OrderStatus::Fulfilled->value)->count(),
                'sales_today_cents' => (int) Order::query()
                    ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Fulfilled->value])
                    ->where('confirmed_at', '>=', $today)
                    ->sum('total_cents'),
            ],
            'recentOrders' => Order::query()
                ->with(['organization:id,name', 'customer:id,first_name,last_name'])
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (Order $order) => OrderController::summary($order)),
            'lowStock' => StockLevel::query()
                ->with(['product:id,sku,name,unit', 'location:id,name'])
                ->whereNotNull('reorder_point')
                ->whereColumn('on_hand', '<=', 'reorder_point')
                ->limit(8)
                ->get()
                ->map(fn (StockLevel $level) => [
                    'id' => $level->id,
                    'product' => $level->product->only('id', 'sku', 'name', 'unit'),
                    'location' => $level->location->name,
                    'on_hand' => $level->on_hand,
                    'reorder_point' => $level->reorder_point,
                ]),
        ]);
    }
}
