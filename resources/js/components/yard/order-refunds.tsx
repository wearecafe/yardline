import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import OrderActionController from '@/actions/App/Http/Controllers/OrderActionController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/yard/field';
import { Section } from '@/components/yard/page-header';
import {
    needsApproval,
    RefundApprovalFields,
} from '@/components/yard/refund-approval';
import type { RefundApprovalProps } from '@/components/yard/refund-approval';
import { formatDate, formatMoney } from '@/lib/format';

export type RefundablePayment = {
    id: number;
    tender: string;
    label: string;
    reference: string | null;
    succeeded_at: string | null;
    amount_cents: number;
    /** What can still go back from it. */
    refundable_cents: number;
};

export type RefundRow = {
    id: number;
    label: string;
    amount_cents: number;
    status: 'pending' | 'succeeded' | 'failed';
    from_stripe: boolean;
    reason: string | null;
    failure_message: string | null;
    by: string | null;
    at: string;
};

export type Refunding = RefundApprovalProps & {
    payments: RefundablePayment[];
    refunds: RefundRow[];
};

type ErrorBag = Record<string, string | undefined>;

/** How a payment goes back, in words. */
function howBack(payment: RefundablePayment): string {
    switch (payment.tender) {
        case 'stripe_checkout':
            return 'Back to the card';
        case 'credit_note':
            return `Back on ${payment.reference ?? 'the credit note'}`;
        default:
            return `Hand back by ${payment.label.toLowerCase()}`;
    }
}

/** Credit put back on a credit note isn't money out, so no limit applies. */
function moneyOut(payment: RefundablePayment, cents: number): number {
    return payment.tender === 'credit_note' ? 0 : cents;
}

/**
 * Payments taken on the order, each with a Refund button, and what's
 * already been refunded.
 */
export function PaymentRefunds({
    orderId,
    refunding,
}: {
    orderId: number;
    refunding: Refunding;
}) {
    const [open, setOpen] = useState<number | null>(null);

    if (refunding.payments.length === 0 && refunding.refunds.length === 0) {
        return null;
    }

    return (
        <div className="mt-4 grid gap-3 border-t border-border pt-3 text-sm">
            <ul className="grid gap-2">
                {refunding.payments.map((p) => (
                    <li key={p.id} className="grid gap-2">
                        <div className="flex items-center justify-between gap-3">
                            <span className="text-muted-foreground">
                                {p.label}, {formatDate(p.succeeded_at)}
                                {p.reference ? `, ${p.reference}` : ''}
                            </span>
                            <span className="flex items-center gap-2">
                                <span className="tabular-nums">
                                    {formatMoney(p.amount_cents)}
                                </span>
                                {p.refundable_cents > 0 && open !== p.id && (
                                    <button
                                        type="button"
                                        className="text-xs font-medium underline-offset-4 hover:underline"
                                        onClick={() => setOpen(p.id)}
                                    >
                                        Refund
                                    </button>
                                )}
                            </span>
                        </div>
                        {open === p.id && (
                            <RefundForm
                                orderId={orderId}
                                payment={p}
                                approval={refunding}
                                onDone={() => setOpen(null)}
                            />
                        )}
                    </li>
                ))}
            </ul>

            {refunding.refunds.length > 0 && (
                <div className="grid gap-1.5">
                    <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Refunds
                    </div>
                    <ul className="grid gap-1.5">
                        {refunding.refunds.map((r) => (
                            <li key={r.id} className="grid gap-0.5">
                                <div className="flex justify-between gap-3">
                                    <span
                                        className={
                                            r.status === 'failed'
                                                ? 'text-danger line-through'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        {r.label}, {formatDate(r.at)}
                                        {r.from_stripe
                                            ? ', in Stripe'
                                            : r.by
                                              ? `, ${r.by}`
                                              : ''}
                                        {r.status === 'pending'
                                            ? ' (on its way)'
                                            : ''}
                                    </span>
                                    <span className="tabular-nums">
                                        −{formatMoney(r.amount_cents)}
                                    </span>
                                </div>
                                {r.status === 'failed' && r.failure_message && (
                                    <span className="text-xs text-danger">
                                        {r.failure_message}
                                    </span>
                                )}
                                {r.reason && r.status !== 'failed' && (
                                    <span className="text-xs text-muted-foreground">
                                        {r.reason}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

function RefundForm({
    orderId,
    payment,
    approval,
    onDone,
}: {
    orderId: number;
    payment: RefundablePayment;
    approval: RefundApprovalProps;
    onDone: () => void;
}) {
    const form = useForm({
        amount: (payment.refundable_cents / 100).toFixed(2),
        reason: '',
        approver_id: '',
        approver_secret: '',
    });
    const cents =
        form.data.amount === ''
            ? payment.refundable_cents
            : Math.round(Number(form.data.amount) * 100);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            amount: d.amount || null,
            reason: d.reason || null,
            approver_id: d.approver_id || null,
            approver_secret: d.approver_secret || null,
        }));
        form.post(
            OrderActionController.refundPayment.url({
                order: orderId,
                payment: payment.id,
            }),
            { preserveScroll: true, onSuccess: onDone },
        );
    }

    return (
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-md border border-border p-3"
        >
            <p className="text-xs text-muted-foreground">
                {howBack(payment)}. Up to{' '}
                {formatMoney(payment.refundable_cents)}.
            </p>
            <Field
                label="Amount"
                htmlFor={`refund-${payment.id}-amount`}
                error={form.errors.amount}
            >
                <Input
                    id={`refund-${payment.id}-amount`}
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                />
            </Field>
            <Field
                label="Reason"
                htmlFor={`refund-${payment.id}-reason`}
                error={form.errors.reason}
            >
                <Input
                    id={`refund-${payment.id}-reason`}
                    value={form.data.reason}
                    placeholder="Optional"
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
            </Field>
            {needsApproval(approval, moneyOut(payment, cents)) && (
                <RefundApprovalFields
                    id={`refund-${payment.id}`}
                    approval={approval}
                    value={{
                        approver_id: form.data.approver_id,
                        approver_secret: form.data.approver_secret,
                    }}
                    onChange={(v) => form.setData((d) => ({ ...d, ...v }))}
                    errors={form.errors as ErrorBag}
                />
            )}
            <div className="flex gap-2">
                <Button type="submit" disabled={form.processing}>
                    Refund {formatMoney(cents)}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Never mind
                </Button>
            </div>
        </form>
    );
}

/**
 * Cancelling a paid order: every payment goes back the way it came first.
 */
export function RefundAndCancelPanel({
    orderId,
    orderNumber,
    refunding,
    onClose,
}: {
    orderId: number;
    orderNumber: string;
    refunding: Refunding;
    onClose: () => void;
}) {
    const form = useForm({
        reason: '',
        approver_id: '',
        approver_secret: '',
    });
    const back = refunding.payments.filter((p) => p.refundable_cents > 0);
    const out = back.reduce(
        (sum, p) => sum + moneyOut(p, p.refundable_cents),
        0,
    );
    const total = back.reduce((sum, p) => sum + p.refundable_cents, 0);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            reason: d.reason || null,
            approver_id: d.approver_id || null,
            approver_secret: d.approver_secret || null,
        }));
        form.post(OrderActionController.refundAndCancel.url(orderId), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <Section title={`Refund and cancel ${orderNumber}`}>
            <form onSubmit={submit} className="grid gap-4 text-sm">
                <ul className="grid gap-1.5">
                    {back.map((p) => (
                        <li key={p.id} className="flex justify-between gap-3">
                            <span className="text-muted-foreground">
                                {howBack(p)}
                            </span>
                            <span className="tabular-nums">
                                {formatMoney(p.refundable_cents)}
                            </span>
                        </li>
                    ))}
                    <li className="flex justify-between gap-3 border-t border-border pt-1.5 font-medium">
                        <span>Refunded</span>
                        <span className="tabular-nums">
                            {formatMoney(total)}
                        </span>
                    </li>
                </ul>
                <Field
                    label="Reason"
                    htmlFor="cancel-reason"
                    error={form.errors.reason}
                >
                    <Input
                        id="cancel-reason"
                        value={form.data.reason}
                        placeholder="Optional"
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                </Field>
                {needsApproval(refunding, out) && (
                    <RefundApprovalFields
                        id="cancel"
                        approval={refunding}
                        value={{
                            approver_id: form.data.approver_id,
                            approver_secret: form.data.approver_secret,
                        }}
                        onChange={(v) => form.setData((d) => ({ ...d, ...v }))}
                        errors={form.errors as ErrorBag}
                    />
                )}
                {(form.errors as ErrorBag).order && (
                    <p role="alert" className="text-sm text-danger">
                        {(form.errors as ErrorBag).order}
                    </p>
                )}
                <div className="flex gap-2">
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={form.processing}
                    >
                        Refund and cancel
                    </Button>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Keep the order
                    </Button>
                </div>
            </form>
        </Section>
    );
}
