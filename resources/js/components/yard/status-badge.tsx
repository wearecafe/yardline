import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import type { OrderStatus, PaymentStatus, Settlement } from '@/types';

const orderStatus: Record<string, [string, ChipTone]> = {
    quote: ['Quote', 'neutral'],
    draft: ['Draft', 'neutral'],
    confirmed: ['Confirmed', 'warning'],
    fulfilled: ['Released', 'success'],
    returned: ['Returned', 'success'],
    cancelled: ['Cancelled', 'neutral'],
};

export function OrderStatusBadge({
    status,
    rental = false,
}: {
    status: OrderStatus | 'quote';
    rental?: boolean;
}) {
    const [label, tone] = orderStatus[status] ?? [status, 'neutral'];

    if (rental && status === 'fulfilled') {
        return <Chip tone="info">On rent</Chip>;
    }

    return <Chip tone={tone}>{label}</Chip>;
}

export function PaymentBadge({
    status,
    settlement,
}: {
    status: PaymentStatus;
    settlement: Settlement | null;
}) {
    if (status === 'paid') {
        return <Chip tone="success">Paid</Chip>;
    }

    if (status === 'partial') {
        return <Chip tone="warning">Part paid</Chip>;
    }

    return (
        <Chip tone="neutral">
            {settlement === 'account' ? 'On account' : 'Unpaid'}
        </Chip>
    );
}
