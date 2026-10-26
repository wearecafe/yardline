import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import { ChevronLeft } from 'lucide-react';
import DeliveryController from '@/actions/App/Http/Controllers/DeliveryController';
import DispatchController from '@/actions/App/Http/Controllers/DispatchController';
import DriverController from '@/actions/App/Http/Controllers/DriverController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import InputError from '@/components/input-error';
import { SignaturePad } from '@/components/yard/signature-pad';
import { useLocationSharing } from '@/hooks/use-location-sharing';
import type { SignaturePadHandle } from '@/components/yard/signature-pad';
import { formatDateTime, formatQuantity } from '@/lib/format';

type Props = {
    shipment: {
        id: number;
        number: string;
        status: string;
        status_label: string;
        delivery: boolean;
        order_number: string;
        order_id: number;
        customer: string;
        contact: { name: string; phone: string | null } | null;
        address: {
            line1?: string | null;
            city?: string | null;
            postal_code?: string | null;
        } | null;
        site_notes: string | null;
        po_number: string | null;
        truck: string | null;
        delivered_at: string | null;
        pod_name: string | null;
        pod_signature_url: string | null;
        pod_photo_url: string | null;
        pod_notes: string | null;
        stop_id: number | null;
    };
    items: {
        order_line_id: number;
        item: string;
        quantity: string;
        unit: string;
        picked_quantity: string | null;
        short: boolean;
    }[];
    weight_kg: number;
};

/**
 * Proof of delivery (or pickup at the counter): who took it, a signature,
 * a photo of the drop.
 */
export default function Deliver({ shipment, items, weight_kg }: Props) {
    const done =
        shipment.status === 'delivered' || shipment.status === 'picked_up';
    const form = useForm<{
        name: string;
        signature: string;
        photo: File | null;
        notes: string;
    }>({
        name: shipment.contact?.name ?? '',
        signature: '',
        photo: null,
        notes: '',
    });
    const pad = useRef<SignaturePadHandle>(null);
    // Drivers keep sharing their location while they take the signature.
    useLocationSharing(usePage().props.shareLocation);
    const where = [
        shipment.address?.line1,
        shipment.address?.city,
        shipment.address?.postal_code,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <>
            <Head
                title={`${shipment.delivery ? 'Deliver' : 'Hand over'} ${shipment.order_number}`}
            />
            <div className="min-h-svh bg-background px-4 py-5">
                <main className="mx-auto flex max-w-lg flex-col gap-4">
                    <Link
                        href={
                            shipment.stop_id !== null
                                ? DriverController.show.url(shipment.stop_id)
                                : shipment.delivery
                                  ? DispatchController.board.url()
                                  : OrderController.show.url(shipment.order_id)
                        }
                        className="flex items-center gap-1 text-sm text-muted-foreground"
                    >
                        <ChevronLeft className="size-4" />{' '}
                        {shipment.stop_id !== null
                            ? 'Stop'
                            : shipment.delivery
                              ? 'Dispatch'
                              : 'Order'}
                    </Link>
                    <header>
                        <div className="figure text-sm text-muted-foreground">
                            {shipment.order_number} · {shipment.number}
                            {shipment.truck ? ` · ${shipment.truck}` : ''}
                        </div>
                        <h1 className="text-2xl font-bold">
                            {shipment.customer}
                        </h1>
                        {shipment.delivery && (
                            <p className="text-muted-foreground">
                                {where || 'No address'}
                            </p>
                        )}
                        {shipment.contact && (
                            <p className="text-sm">
                                {shipment.contact.name}
                                {shipment.contact.phone && (
                                    <>
                                        {' · '}
                                        <a
                                            href={`tel:${shipment.contact.phone}`}
                                            className="font-medium underline underline-offset-4"
                                        >
                                            {shipment.contact.phone}
                                        </a>
                                    </>
                                )}
                            </p>
                        )}
                        {shipment.site_notes && (
                            <p className="mt-1 rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning">
                                {shipment.site_notes}
                            </p>
                        )}
                    </header>

                    <section className="rounded-[10px] border border-border bg-card p-4">
                        <ul className="grid gap-2 text-sm">
                            {items.map((i) => (
                                <li
                                    key={i.order_line_id}
                                    className="flex justify-between gap-3"
                                >
                                    <span>{i.item}</span>
                                    <span className="figure font-semibold">
                                        {formatQuantity(
                                            i.picked_quantity ?? i.quantity,
                                        )}{' '}
                                        {i.unit}
                                        {i.short ? ' (short)' : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {weight_kg > 0 && (
                            <p className="mt-2 border-t border-border pt-2 text-sm text-muted-foreground">
                                ≈ {(weight_kg / 1000).toFixed(1)} t
                                {shipment.po_number
                                    ? ` · PO ${shipment.po_number}`
                                    : ''}
                            </p>
                        )}
                    </section>

                    {done ? (
                        <section className="grid gap-2 rounded-[10px] border border-border bg-card p-4 text-sm">
                            <div className="font-semibold text-success">
                                {shipment.status_label}{' '}
                                {formatDateTime(shipment.delivered_at)}
                            </div>
                            <div>Received by {shipment.pod_name}</div>
                            {shipment.pod_signature_url && (
                                <img
                                    src={shipment.pod_signature_url}
                                    alt={`Signature of ${shipment.pod_name}`}
                                    className="h-20 w-auto self-start rounded border border-border bg-white"
                                />
                            )}
                            {shipment.pod_photo_url && (
                                <img
                                    src={shipment.pod_photo_url}
                                    alt="Photo of the drop"
                                    className="max-h-64 w-auto self-start rounded"
                                />
                            )}
                            {shipment.pod_notes && (
                                <p className="text-muted-foreground">
                                    {shipment.pod_notes}
                                </p>
                            )}
                        </section>
                    ) : shipment.status === 'picking' ? (
                        <p className="rounded-lg bg-secondary px-3 py-2 text-sm">
                            This load is still being picked.
                        </p>
                    ) : (
                        <form
                            className="grid gap-3 rounded-[10px] border border-border bg-card p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.transform((d) => ({
                                    ...d,
                                    signature:
                                        pad.current && !pad.current.isEmpty()
                                            ? pad.current.toDataUrl()
                                            : '',
                                }));
                                form.post(
                                    DeliveryController.complete.url(
                                        shipment.id,
                                    ),
                                    {
                                        forceFormData: true,
                                        preserveScroll: true,
                                    },
                                );
                            }}
                        >
                            <label className="grid gap-1">
                                <span className="text-sm font-medium">
                                    Received by
                                </span>
                                <input
                                    required
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    className="h-11 rounded-lg border border-input bg-card px-3"
                                />
                                <InputError message={form.errors.name} />
                            </label>
                            <div className="grid gap-1">
                                <SignaturePad ref={pad} label="Signature" />
                                <InputError message={form.errors.signature} />
                            </div>
                            <label className="grid gap-1">
                                <span className="text-sm font-medium">
                                    Photo of the drop{' '}
                                    {shipment.delivery
                                        ? '(if nobody is there to sign)'
                                        : '(optional)'}
                                </span>
                                <input
                                    type="file"
                                    accept="image/*"
                                    capture="environment"
                                    onChange={(e) =>
                                        form.setData(
                                            'photo',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                    className="text-sm"
                                />
                                <InputError message={form.errors.photo} />
                            </label>
                            <label className="grid gap-1">
                                <span className="text-sm font-medium">
                                    Notes
                                </span>
                                <input
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                    placeholder="Left by the garage"
                                    className="h-11 rounded-lg border border-input bg-card px-3"
                                />
                            </label>
                            {(form.errors as Record<string, string>).order && (
                                <InputError
                                    message={
                                        (form.errors as Record<string, string>)
                                            .order
                                    }
                                />
                            )}
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="h-14 rounded-xl bg-primary text-lg font-bold text-white hover:bg-[#9a3412] disabled:opacity-50"
                            >
                                {shipment.delivery ? 'Delivered' : 'Picked up'}
                            </button>
                        </form>
                    )}
                </main>
            </div>
        </>
    );
}
