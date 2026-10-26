import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import PortalController from '@/actions/App/Http/Controllers/Portal/PortalController';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { statusTone } from '@/pages/portal/home';
import type { PortalOrder } from '@/pages/portal/home';

/**
 * Orders as tappable cards, for phones. Tables take over from md up.
 */
export function PortalOrderCards({
    orders,
    showTotal = false,
    className,
}: {
    orders: PortalOrder[];
    showTotal?: boolean;
    className?: string;
}) {
    if (orders.length === 0) {
        return (
            <p
                className={cn(
                    'px-4 py-6 text-center text-sm text-muted-foreground',
                    className,
                )}
            >
                No orders yet.
            </p>
        );
    }

    return (
        <ul className={cn('divide-y divide-secondary', className)}>
            {orders.map((o) => (
                <li key={o.id}>
                    <Link
                        href={PortalController.order.url(o.id)}
                        className="flex items-center gap-3 px-4 py-3 active:bg-accent"
                    >
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center justify-between gap-2">
                                <span className="figure font-semibold">
                                    {o.number}
                                </span>
                                <span
                                    className={cn(
                                        'rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap',
                                        statusTone(o.status),
                                    )}
                                >
                                    {o.status}
                                </span>
                            </div>
                            <div className="mt-0.5 truncate text-sm">
                                {o.site}
                            </div>
                            <div className="truncate text-xs text-muted-foreground">
                                {formatDate(o.created_at)}
                                {showTotal
                                    ? ` · ${formatMoney(o.total_cents)}`
                                    : ''}
                                {o.items ? ` · ${o.items}` : ''}
                            </div>
                        </div>
                        <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                    </Link>
                </li>
            ))}
        </ul>
    );
}
