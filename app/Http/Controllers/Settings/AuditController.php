<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit log: changes to orders, prices and credit terms.
 */
class AuditController extends Controller
{
    public const KINDS = [
        'order' => 'Orders',
        'order_line' => 'Order lines',
        'product' => 'Products',
        'product_unit' => 'Selling units',
        'price_list_item' => 'Price lists',
        'contract_price' => 'Contract prices',
        'organization' => 'Credit terms',
        'credit_note' => 'Credits',
    ];

    public function index(Request $request): Response
    {
        $kind = $request->string('kind')->toString();
        $search = $request->string('search')->toString();

        $events = AuditEvent::query()
            ->with(['user:id,name', 'customer:id,first_name,last_name'])
            ->when(isset(self::KINDS[$kind]), fn ($q) => $q->where('subject_type', $kind))
            ->when($search !== '', fn ($q) => $q->whereLike('subject_label', "%{$search}%"))
            ->where(fn ($q) => $q->where('subject_type', '!=', 'order_line')->orWhere('event', 'updated'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditEvent $e) => self::present($e));

        return Inertia::render('settings/audit', [
            'events' => $events,
            'kinds' => collect(self::KINDS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'filters' => ['kind' => $kind ?: null, 'search' => $search ?: null],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function historyFor(Model $subject, int $limit = 20): array
    {
        return array_values(AuditEvent::query()
            ->with(['user:id,name', 'customer:id,first_name,last_name'])
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditEvent $e) => self::present($e))
            ->values()
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(AuditEvent $e): array
    {
        return [
            'id' => $e->id,
            'at' => $e->created_at->toIso8601String(),
            'who' => $e->user->name ?? ($e->customer ? $e->customer->full_name.' (portal)' : 'System'),
            'kind' => self::KINDS[$e->subject_type] ?? $e->subject_type,
            'subject_type' => $e->subject_type,
            'subject_id' => $e->subject_id,
            'label' => $e->subject_label,
            'event' => $e->event,
            'changes' => $e->changes,
            'note' => $e->note,
        ];
    }
}
