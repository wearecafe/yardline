import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { formatDateTime, formatQuantity } from '@/lib/format';

type Props = {
    shipment: { number: string; created_at: string; picker: string | null };
    order: {
        number: string;
        customer: string;
        mode: string;
        po_number: string | null;
        delivery_address: {
            line1?: string | null;
            city?: string | null;
            postal_code?: string | null;
        } | null;
        notes: string | null;
        site_notes: string | null;
        yard: string;
    };
    items: {
        order_line_id: number;
        item: string;
        sku: string | null;
        where: string | null;
        quantity: string;
        unit: string;
    }[];
    weight_kg: number;
    deposits: { label: string } | null;
    sign_offs: {
        id: number;
        stage: string;
        outcome: 'signed_off' | 'rejected';
        by: string | null;
        at: string;
    }[];
};

/**
 * A pick ticket for the clipboard: prints itself.
 */
export default function PickTicket({
    shipment,
    order,
    items,
    weight_kg,
    deposits,
    sign_offs,
}: Props) {
    const checked = sign_offs.filter((c) => c.outcome === 'signed_off');

    useEffect(() => {
        const t = setTimeout(() => window.print(), 300);

        return () => clearTimeout(t);
    }, []);

    return (
        <>
            <Head title={`Pick ticket ${order.number}`} />
            <main className="mx-auto max-w-2xl bg-white p-8 text-[14px] text-black">
                <header className="flex items-start justify-between border-b-2 border-black pb-3">
                    <div>
                        <div className="text-xs tracking-wide uppercase">
                            Pick ticket · {order.yard}
                        </div>
                        <h1 className="text-3xl font-bold">{order.number}</h1>
                        <div className="text-lg font-semibold">
                            {order.customer}
                        </div>
                    </div>
                    <div className="text-right text-sm">
                        <div className="font-mono">{shipment.number}</div>
                        <div>{formatDateTime(shipment.created_at)}</div>
                        {shipment.picker && (
                            <div>Picker: {shipment.picker}</div>
                        )}
                    </div>
                </header>
                <p className="mt-3">
                    <strong>{order.mode}</strong>
                    {order.delivery_address?.line1 &&
                        ` · ${[order.delivery_address.line1, order.delivery_address.city, order.delivery_address.postal_code].filter(Boolean).join(', ')}`}
                    {order.po_number && ` · PO ${order.po_number}`}
                </p>
                {(order.site_notes || order.notes) && (
                    <p className="mt-1 italic">
                        {[order.site_notes, order.notes]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                )}
                <table className="mt-4 w-full border-collapse">
                    <thead>
                        <tr className="border-b border-black text-left text-xs uppercase">
                            <th className="w-8 py-2">✓</th>
                            <th className="py-2">Item</th>
                            <th className="py-2">Where</th>
                            <th className="py-2 text-right">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((i) => (
                            <tr
                                key={i.order_line_id}
                                className="border-b border-gray-300"
                            >
                                <td className="py-3">
                                    <span className="inline-block size-4 border border-black" />
                                </td>
                                <td className="py-3">
                                    <div className="font-semibold">
                                        {i.item}
                                    </div>
                                    {i.sku && (
                                        <div className="font-mono text-xs">
                                            {i.sku}
                                        </div>
                                    )}
                                </td>
                                <td className="py-3">{i.where ?? ''}</td>
                                <td className="py-3 text-right font-mono text-base font-bold">
                                    {formatQuantity(i.quantity)} {i.unit}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <p className="mt-4 text-sm">
                    {weight_kg > 0 &&
                        `Load ≈ ${(weight_kg / 1000).toFixed(1)} t`}
                    {deposits && ` · Deposits ${deposits.label}`}
                </p>
                {checked.length > 0 && (
                    <ul className="mt-4 text-sm">
                        {checked.map((c) => (
                            <li key={c.id}>
                                {c.stage} checked by {c.by ?? 'someone'},{' '}
                                {formatDateTime(c.at)}
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-10 grid grid-cols-2 gap-8 text-sm">
                    <div className="border-t border-black pt-1">Picked by</div>
                    <div className="border-t border-black pt-1">Checked by</div>
                </div>
            </main>
        </>
    );
}
