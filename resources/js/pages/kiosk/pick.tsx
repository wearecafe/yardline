import { Head, Link, router } from '@inertiajs/react';
import { Check, ChevronLeft } from 'lucide-react';
import { useState } from 'react';
import FulfillmentController from '@/actions/App/Http/Controllers/FulfillmentController';
import { formatQuantity } from '@/lib/format';
import { cn } from '@/lib/utils';

type Item = {
    id: number;
    order_line_id: number;
    item: string;
    sku: string | null;
    where: string | null;
    quantity: string;
    unit: string;
    weighed: boolean;
    picked_quantity: string | null;
    short: boolean;
    note: string | null;
    picked: boolean;
};

type Props = {
    shipment: {
        id: number;
        number: string;
        status: string;
        delivery: boolean;
        order_id: number;
        order_number: string;
        customer: string;
        mode: string;
    };
    items: Item[];
    weight_kg: number;
    tolerance: number;
};

/**
 * The yard picker (yard mockup 4): dark, big targets, one line at a time
 * for a phone in the yard. Weighed material takes a scale reading.
 */
export default function Pick({ shipment, items, tolerance }: Props) {
    const picked = items.filter((i) => i.picked).length;
    const left = items.length - picked;
    const loading = shipment.status !== 'picking';

    return (
        <>
            <Head title={`Pick ${shipment.order_number}`} />
            <div className="min-h-svh bg-[#1c1917] px-4 pt-4 pb-28 text-[#fafaf9]">
                <div className="mx-auto flex max-w-md flex-col gap-4">
                    <header className="flex flex-col gap-1">
                        <Link
                            href={FulfillmentController.queue.url({
                                query: { card: `s${shipment.id}` },
                            })}
                            className="flex items-center gap-1 text-sm text-[#d6d3d1]"
                        >
                            <ChevronLeft className="size-4" /> Queue
                        </Link>
                        <div className="figure text-sm text-[#d6d3d1]">
                            {shipment.order_number} · {shipment.number}
                        </div>
                        <h1 className="text-2xl font-bold">
                            {shipment.customer}
                        </h1>
                        <p className="text-sm text-[#d6d3d1]">
                            {shipment.mode} · {picked} of {items.length} picked
                        </p>
                    </header>

                    {loading && (
                        <p className="rounded-xl bg-[#292524] p-4 text-sm">
                            This load has gone. Nothing left to pick.
                        </p>
                    )}

                    <ol className="flex flex-col gap-3">
                        {items.map((item) => (
                            <PickRow
                                key={item.id}
                                shipmentId={shipment.id}
                                item={item}
                                tolerance={tolerance}
                                disabled={loading}
                            />
                        ))}
                    </ol>
                </div>

                {!loading && (
                    <div className="fixed inset-x-0 bottom-0 bg-[#1c1917]/95 p-4">
                        <button
                            type="button"
                            disabled={left > 0}
                            onClick={() =>
                                router.post(
                                    FulfillmentController.load.url(shipment.id),
                                    { from_picker: true },
                                )
                            }
                            className="mx-auto block h-14 w-full max-w-md rounded-xl bg-[#c2410c] text-lg font-bold text-white disabled:bg-[#44403c] disabled:text-[#a8a29e]"
                        >
                            {left > 0
                                ? `${shipment.delivery ? 'Mark loaded' : 'Ready for pickup'} (${left} left)`
                                : shipment.delivery
                                  ? 'Mark loaded'
                                  : 'Ready for pickup'}
                        </button>
                    </div>
                )}
            </div>
        </>
    );
}

function PickRow({
    shipmentId,
    item,
    tolerance,
    disabled,
}: {
    shipmentId: number;
    item: Item;
    tolerance: number;
    disabled: boolean;
}) {
    const [reading, setReading] = useState(
        item.picked_quantity ? String(Number(item.picked_quantity)) : '',
    );
    const [shortOpen, setShortOpen] = useState(false);
    const [shortQty, setShortQty] = useState('');
    const [note, setNote] = useState('');
    const ordered = Number(item.quantity);
    const value = Number(reading);
    const within =
        reading !== '' &&
        ordered > 0 &&
        Math.abs(value - ordered) / ordered <= tolerance;
    const url = FulfillmentController.record.url({
        shipment: shipmentId,
        line: item.id,
    });

    function confirm(
        quantity: number,
        short = false,
        withNote: string | null = null,
    ) {
        router.post(
            url,
            { picked_quantity: quantity, short, note: withNote },
            { preserveScroll: true },
        );
    }

    return (
        <li
            className={cn(
                'rounded-xl bg-[#292524] p-4',
                item.picked && 'opacity-70',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="text-lg font-semibold">{item.item}</div>
                    {item.where && (
                        <div className="text-sm text-[#d6d3d1]">
                            {item.where}
                        </div>
                    )}
                </div>
                <div className="figure text-xl font-bold whitespace-nowrap">
                    {formatQuantity(item.quantity)} {item.unit}
                </div>
            </div>

            {item.picked ? (
                <div className="mt-3 flex items-center gap-2 text-sm text-[#86efac]">
                    <Check className="size-4" />
                    {item.short
                        ? `Short: ${formatQuantity(item.picked_quantity)} ${item.unit}`
                        : `Picked ${formatQuantity(item.picked_quantity)} ${item.unit}`}
                    {item.note && (
                        <span className="text-[#d6d3d1]">· {item.note}</span>
                    )}
                </div>
            ) : disabled ? null : item.weighed ? (
                <div className="mt-3 grid gap-2">
                    <label className="grid gap-1">
                        <span className="text-sm text-[#d6d3d1]">
                            Scale reading (net, {item.unit})
                        </span>
                        <input
                            inputMode="decimal"
                            value={reading}
                            onChange={(e) => setReading(e.target.value)}
                            className="figure h-14 rounded-xl border border-[#44403c] bg-[#1c1917] px-4 text-2xl font-bold text-white"
                        />
                    </label>
                    {reading !== '' && (
                        <div
                            className={cn(
                                'rounded-lg px-3 py-2 text-sm font-semibold',
                                within
                                    ? 'bg-[#dcfce7] text-[#166534]'
                                    : 'bg-[#fef3c7] text-[#92400e]',
                            )}
                        >
                            {within
                                ? `Within ${Math.round(tolerance * 100)}%`
                                : `Outside ${Math.round(tolerance * 100)}%: ${value < ordered ? 'short' : 'over'} by ${formatQuantity(Math.abs(value - ordered).toFixed(3))} ${item.unit}`}
                        </div>
                    )}
                    <button
                        type="button"
                        disabled={reading === ''}
                        onClick={() =>
                            confirm(value, value < ordered * (1 - tolerance))
                        }
                        className="h-12 rounded-xl bg-[#15803d] font-bold text-white disabled:opacity-40"
                    >
                        Confirm {reading || '—'} {item.unit}
                    </button>
                </div>
            ) : (
                <div className="mt-3 grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        onClick={() => confirm(ordered)}
                        className="h-12 rounded-xl bg-[#15803d] font-bold text-white"
                    >
                        Picked
                    </button>
                    <button
                        type="button"
                        onClick={() => setShortOpen(!shortOpen)}
                        className="h-12 rounded-xl border border-[#57534e] font-semibold"
                    >
                        Short or substitute
                    </button>
                </div>
            )}

            {item.weighed && !item.picked && !disabled && (
                <button
                    type="button"
                    onClick={() => setShortOpen(!shortOpen)}
                    className="mt-2 text-sm text-[#d6d3d1] underline underline-offset-4"
                >
                    Short or substitute
                </button>
            )}

            {shortOpen && !item.picked && (
                <div className="mt-3 grid gap-2 rounded-lg border border-[#44403c] p-3">
                    <label className="grid gap-1 text-sm">
                        <span className="text-[#d6d3d1]">
                            Quantity loaded ({item.unit})
                        </span>
                        <input
                            inputMode="decimal"
                            value={shortQty}
                            onChange={(e) => setShortQty(e.target.value)}
                            className="figure h-12 rounded-lg border border-[#44403c] bg-[#1c1917] px-3 text-lg text-white"
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        <span className="text-[#d6d3d1]">
                            Note (what's short, or the substitute)
                        </span>
                        <input
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="Only 4 pallets of run 2611-B"
                            className="h-12 rounded-lg border border-[#44403c] bg-[#1c1917] px-3 text-white"
                        />
                    </label>
                    <button
                        type="button"
                        disabled={shortQty === ''}
                        onClick={() =>
                            confirm(Number(shortQty), true, note || null)
                        }
                        className="h-12 rounded-xl bg-[#fb923c] font-bold text-[#1c1917] disabled:opacity-40"
                    >
                        Record short
                    </button>
                </div>
            )}
        </li>
    );
}
