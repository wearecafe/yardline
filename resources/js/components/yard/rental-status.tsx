import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';

const rentalStatus: Record<string, [string, ChipTone]> = {
    quote: ['Quote', 'neutral'],
    reserved: ['Reserved', 'neutral'],
    out: ['On rent', 'info'],
    overdue: ['Overdue', 'danger'],
    returned: ['Returned', 'success'],
    cancelled: ['Cancelled', 'neutral'],
};

export function RentalStatusChip({ status }: { status: string }) {
    const [label, tone] = rentalStatus[status] ?? [status, 'neutral'];

    return <Chip tone={tone}>{label}</Chip>;
}

const unitStatus: Record<string, [string, ChipTone]> = {
    available: ['Available', 'success'],
    on_rent: ['On rent', 'info'],
    overdue: ['Overdue', 'danger'],
    in_shop: ['In shop', 'warning'],
    reserved: ['Reserved', 'neutral'],
    service_due: ['Service due', 'warning'],
    retired: ['Retired', 'neutral'],
};

export function UnitStatusChip({ status }: { status: string }) {
    const [label, tone] = unitStatus[status] ?? [status, 'neutral'];

    return <Chip tone={tone}>{label}</Chip>;
}
