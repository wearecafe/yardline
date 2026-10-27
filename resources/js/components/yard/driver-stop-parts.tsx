import { Link } from '@inertiajs/react';
import { Navigation, Phone } from 'lucide-react';
import DeliveryController from '@/actions/App/Http/Controllers/DeliveryController';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { cn } from '@/lib/utils';
import type { DriverStop } from '@/types/driver';

export function stopStatus(stop: DriverStop): {
    tone: ChipTone;
    label: string;
} {
    if (stop.status === 'done') {
        return {
            tone: 'success',
            label: `${stop.kind === 'delivery' ? 'Delivered' : 'Done'}${stop.done_label ? ` ${stop.done_label}` : ''}`,
        };
    }

    if (stop.status === 'en_route') {
        return {
            tone: 'info',
            label: `On its way${stop.eta_label ? ` · ETA ${stop.eta_label}` : ''}`,
        };
    }

    if (!stop.loaded) {
        return { tone: 'warning', label: 'Not loaded yet' };
    }

    return { tone: 'neutral', label: 'Scheduled' };
}

export function StopStatusChip({ stop }: { stop: DriverStop }) {
    const { tone, label } = stopStatus(stop);

    return <Chip tone={tone}>{label}</Chip>;
}

/** Directions, a call, and proof of delivery: what a driver taps most. */
export function StopActions({
    stop,
    className,
}: {
    stop: DriverStop;
    className?: string;
}) {
    const canProve =
        stop.kind === 'delivery' &&
        stop.shipment_id !== null &&
        stop.status !== 'done' &&
        ['loaded', 'out', 'ready'].includes(stop.shipment_status ?? '');

    return (
        <div className={cn('flex flex-wrap gap-2', className)}>
            {stop.map_url && (
                <a
                    href={stop.map_url}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl border border-border bg-card px-4 font-semibold"
                >
                    <Navigation className="size-4" /> Directions
                </a>
            )}
            {stop.contact?.phone && (
                <a
                    href={`tel:${stop.contact.phone}`}
                    className="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl border border-border bg-card px-4 font-semibold"
                >
                    <Phone className="size-4" /> Call
                </a>
            )}
            {canProve && stop.shipment_id !== null && (
                <Link
                    href={DeliveryController.show(stop.shipment_id)}
                    className="inline-flex h-11 w-full items-center justify-center rounded-xl bg-primary px-4 font-bold text-white hover:bg-[#9a3412]"
                >
                    Proof of delivery
                </Link>
            )}
        </div>
    );
}
