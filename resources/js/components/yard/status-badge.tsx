import { cn } from '@/lib/utils';
import type { OrderStatus, PaymentStatus, Settlement } from '@/types';

const statusStyles: Record<OrderStatus, string> = {
    draft: 'border-dashed border-muted-foreground/60 text-muted-foreground',
    confirmed: 'border-tag bg-tag/20 text-foreground',
    fulfilled: 'border-moss/50 bg-moss/10 text-moss',
    returned: 'border-moss/50 bg-moss/10 text-moss',
    cancelled: 'border-border text-muted-foreground line-through',
};

const statusLabels: Record<OrderStatus, string> = {
    draft: 'Draft',
    confirmed: 'Confirmed',
    fulfilled: 'Released',
    returned: 'Returned',
    cancelled: 'Cancelled',
};

export function OrderStatusBadge({
    status,
    rental = false,
}: {
    status: OrderStatus;
    rental?: boolean;
}) {
    const label =
        rental && status === 'fulfilled' ? 'Out on rent' : statusLabels[status];

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-sm border px-1.5 py-0.5 text-xs font-medium whitespace-nowrap',
                statusStyles[status],
            )}
        >
            {label}
        </span>
    );
}

export function PaymentBadge({
    status,
    settlement,
}: {
    status: PaymentStatus;
    settlement: Settlement | null;
}) {
    if (status === 'paid') {
        return <span className="text-xs font-medium text-moss">Paid</span>;
    }

    if (status === 'partial') {
        return <span className="text-xs font-medium">Part paid</span>;
    }

    return (
        <span className="text-xs text-muted-foreground">
            {settlement === 'account' ? 'On account' : 'Unpaid'}
        </span>
    );
}
