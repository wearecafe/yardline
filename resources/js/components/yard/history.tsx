import { formatDateTime, formatMoney } from '@/lib/format';

export type HistoryEvent = {
    id: number;
    at: string;
    who: string;
    kind: string;
    subject_type: string;
    subject_id: number;
    label: string | null;
    event: string;
    changes: Record<string, [unknown, unknown]> | null;
    note: string | null;
};

const fieldLabels: Record<string, string> = {
    status: 'Status',
    settlement: 'Paid by',
    payment_status: 'Payment',
    credit_override_reason: 'Credit override',
    total_cents: 'Total',
    po_number: 'PO',
    organization_id: 'Account',
    delivery_fee_cents: 'Delivery fee',
    quote_expires_on: 'Quote expiry',
    revision: 'Quote revision',
    quantity: 'Quantity',
    unit_price_cents: 'Price',
    price_overridden: 'Price set by hand',
    price_cents: 'Price',
    cost_cents: 'Cost',
    is_active: 'Active',
    taxable: 'Taxable',
    price_list_exempt: 'Exempt from price lists',
    exempt_price_cents: 'Exempt product price',
    category: 'Category',
    discount_type: 'Discount type',
    discount_percent: 'Discount %',
    discount_cents: 'Discount',
    factor: 'Conversion',
    deposit_cents: 'Deposit',
    min_quantity: 'From quantity',
    starts_on: 'From',
    ends_on: 'Until',
    credit_limit_cents: 'Credit limit',
    payment_terms: 'Terms',
    credit_hold: 'Credit hold',
    price_list_id: 'Price list',
    tax_exempt: 'Tax exempt',
    applied_cents: 'Applied',
    refunded_cents: 'Refunded',
};

function show(field: string, value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (field.endsWith('_cents') && typeof value === 'number') {
        return formatMoney(value);
    }

    if (typeof value === 'boolean') {
        return value ? 'yes' : 'no';
    }

    return String(value as string | number).replaceAll('_', ' ');
}

/**
 * "Who changed what" for one record, or for the whole log.
 */
export function History({
    events,
    showSubject = false,
}: {
    events: HistoryEvent[];
    showSubject?: boolean;
}) {
    if (events.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No changes recorded yet.
            </p>
        );
    }

    return (
        <ol className="grid gap-3 text-sm">
            {events.map((e) => (
                <li key={e.id} className="grid gap-0.5">
                    <div className="text-muted-foreground">
                        {formatDateTime(e.at)} · {e.who}
                        {showSubject &&
                            ` · ${e.kind}${e.label ? ` ${e.label}` : ''}`}
                    </div>
                    {e.event === 'updated' && e.changes ? (
                        <ul>
                            {Object.entries(e.changes).map(
                                ([field, [from, to]]) => (
                                    <li key={field}>
                                        <span className="font-medium">
                                            {fieldLabels[field] ?? field}
                                        </span>
                                        : {show(field, from)} →{' '}
                                        {show(field, to)}
                                    </li>
                                ),
                            )}
                        </ul>
                    ) : (
                        <div className="font-medium capitalize">
                            {e.event}
                            {e.label && !showSubject ? ` ${e.label}` : ''}
                        </div>
                    )}
                    {e.note && (
                        <div className="text-muted-foreground">{e.note}</div>
                    )}
                </li>
            ))}
        </ol>
    );
}
