import { Head, Link, useForm } from '@inertiajs/react';
import {
    RefundApprovalFields,
    needsApproval,
} from '@/components/yard/refund-approval';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import ReturnController from '@/actions/App/Http/Controllers/ReturnController';
import { Chip } from '@/components/yard/chip';
import { Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    formatDate,
    formatMoney,
    formatQuantity,
    unitLabel,
} from '@/lib/format';
import type { Option } from '@/types';

type Props = {
    credit: {
        id: number;
        number: string;
        kind: string;
        reason: string | null;
        subtotal_cents: number;
        restocking_fee_cents: number;
        tax_cents: number;
        deposit_cents: number;
        pallet_fee_cents: number;
        total_cents: number;
        applied_cents: number;
        refunded_cents: number;
        refund_tender: string | null;
        remaining_cents: number;
        status: string;
        issued_on: string;
        who: string;
        organization_id: number | null;
        order_id: number | null;
        order_number: string | null;
        created_by: string | null;
        lines: {
            id: number;
            description: string;
            quantity: string;
            unit: string;
            unit_price_cents: number;
            line_total_cents: number;
            restock: boolean;
        }[];
    };
    openOrders: { id: number; number: string; balance_cents: number }[];
    tenders: Option[];
    refundLimitCents: number | null;
    approvers: { id: number; name: string }[];
};

/**
 * A credit note: what it's for, and spending it on an invoice or a refund.
 */
export default function CreditNotePage({
    credit,
    openOrders,
    tenders,
    refundLimitCents,
    approvers,
}: Props) {
    const apply = useForm({
        order_id: String(openOrders[0]?.id ?? ''),
        amount: '',
    });
    const refund = useForm({
        tender: 'card_terminal',
        amount: '',
        approver_id: '',
        approver_secret: '',
    });
    const approval = { limit_cents: refundLimitCents, approvers };
    const refundCents =
        refund.data.amount === ''
            ? credit.remaining_cents
            : Math.round(Number(refund.data.amount) * 100);

    return (
        <>
            <Head title={credit.number} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <Link
                            href={ReturnController.index()}
                            className="hover:underline"
                        >
                            Returns and credits
                        </Link>
                    }
                    title={
                        <span className="flex flex-wrap items-center gap-3">
                            Credit {credit.number}
                            <Chip
                                tone={
                                    credit.remaining_cents > 0
                                        ? 'info'
                                        : 'neutral'
                                }
                            >
                                {credit.remaining_cents > 0
                                    ? `${formatMoney(credit.remaining_cents)} to use`
                                    : 'Used'}
                            </Chip>
                        </span>
                    }
                    description={
                        <>
                            {credit.organization_id ? (
                                <Link
                                    href={OrganizationController.show(
                                        credit.organization_id,
                                    )}
                                    className="hover:underline"
                                >
                                    {credit.who}
                                </Link>
                            ) : (
                                credit.who
                            )}
                            {credit.order_id && (
                                <>
                                    {' · '}
                                    <Link
                                        href={OrderController.show(
                                            credit.order_id,
                                        )}
                                        className="figure hover:underline"
                                    >
                                        {credit.order_number}
                                    </Link>
                                </>
                            )}
                            {` · ${formatDate(credit.issued_on)}`}
                            {credit.created_by ? ` · ${credit.created_by}` : ''}
                        </>
                    }
                />
                <div className="flex flex-wrap items-start gap-5">
                    <div className="flex min-w-0 flex-[999_1_560px] flex-col gap-5">
                        {credit.lines.length > 0 && (
                            <Section title="Returned" flush>
                                <Table>
                                    <thead>
                                        <tr>
                                            <Th>Item</Th>
                                            <Th numeric>Quantity</Th>
                                            <Th>Condition</Th>
                                            <Th numeric>Credit</Th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {credit.lines.map((l) => (
                                            <tr key={l.id}>
                                                <Td>{l.description}</Td>
                                                <Td numeric>
                                                    {formatQuantity(l.quantity)}{' '}
                                                    {unitLabel(l.unit)}
                                                </Td>
                                                <Td>
                                                    {l.restock
                                                        ? 'Restocked'
                                                        : 'Written off'}
                                                </Td>
                                                <Td numeric>
                                                    {formatMoney(
                                                        l.line_total_cents,
                                                    )}
                                                </Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </Table>
                            </Section>
                        )}
                        <Section title="Amount">
                            {credit.reason && (
                                <p className="text-sm">{credit.reason}</p>
                            )}
                            <dl className="grid max-w-sm grid-cols-[1fr_auto] gap-y-1.5 text-sm">
                                {credit.subtotal_cents > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Goods
                                        </dt>
                                        <dd className="figure text-right">
                                            {formatMoney(credit.subtotal_cents)}
                                        </dd>
                                    </>
                                )}
                                {credit.restocking_fee_cents > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Restocking fee
                                        </dt>
                                        <dd className="figure text-right">
                                            −
                                            {formatMoney(
                                                credit.restocking_fee_cents,
                                            )}
                                        </dd>
                                    </>
                                )}
                                {credit.pallet_fee_cents > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Pallet usage fees (full pallets
                                            back)
                                        </dt>
                                        <dd className="figure text-right">
                                            {formatMoney(
                                                credit.pallet_fee_cents,
                                            )}
                                        </dd>
                                    </>
                                )}
                                {credit.tax_cents > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Tax
                                        </dt>
                                        <dd className="figure text-right">
                                            {formatMoney(credit.tax_cents)}
                                        </dd>
                                    </>
                                )}
                                {credit.deposit_cents > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Deposits
                                        </dt>
                                        <dd className="figure text-right">
                                            {formatMoney(credit.deposit_cents)}
                                        </dd>
                                    </>
                                )}
                                <dt className="border-t border-border pt-2 font-semibold">
                                    Credit
                                </dt>
                                <dd className="figure border-t border-border pt-2 text-right font-semibold">
                                    {formatMoney(credit.total_cents)}
                                </dd>
                                {credit.applied_cents > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Applied to invoices
                                        </dt>
                                        <dd className="figure text-right">
                                            −{formatMoney(credit.applied_cents)}
                                        </dd>
                                    </>
                                )}
                                {credit.refunded_cents > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Refunded
                                        </dt>
                                        <dd className="figure text-right">
                                            −
                                            {formatMoney(credit.refunded_cents)}
                                        </dd>
                                    </>
                                )}
                            </dl>
                        </Section>
                    </div>

                    {credit.remaining_cents > 0 && (
                        <div className="flex min-w-0 flex-[1_1_300px] flex-col gap-5">
                            <Section title="Apply to an invoice">
                                {openOrders.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Nothing open on this account. It stays
                                        as credit for the next order.
                                    </p>
                                ) : (
                                    <form
                                        className="grid gap-3"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            apply.transform((d) => ({
                                                ...d,
                                                amount: d.amount || null,
                                            }));
                                            apply.post(
                                                ReturnController.apply.url(
                                                    credit.id,
                                                ),
                                                { preserveScroll: true },
                                            );
                                        }}
                                    >
                                        <Field
                                            label="Order"
                                            htmlFor="apply_order"
                                            error={apply.errors.order_id}
                                        >
                                            <NativeSelect
                                                id="apply_order"
                                                value={apply.data.order_id}
                                                onChange={(e) =>
                                                    apply.setData(
                                                        'order_id',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                {openOrders.map((o) => (
                                                    <option
                                                        key={o.id}
                                                        value={o.id}
                                                    >
                                                        {o.number} ·{' '}
                                                        {formatMoney(
                                                            o.balance_cents,
                                                        )}{' '}
                                                        owing
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </Field>
                                        <Field
                                            label="Amount"
                                            htmlFor="apply_amount"
                                            error={apply.errors.amount}
                                            hint="Blank for as much as covers it."
                                        >
                                            <Input
                                                id="apply_amount"
                                                inputMode="decimal"
                                                value={apply.data.amount}
                                                onChange={(e) =>
                                                    apply.setData(
                                                        'amount',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            disabled={apply.processing}
                                        >
                                            Apply credit
                                        </Button>
                                    </form>
                                )}
                            </Section>
                            <Section title="Refund">
                                <form
                                    className="grid gap-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        refund.transform((d) => ({
                                            ...d,
                                            amount: d.amount || null,
                                            approver_id: d.approver_id || null,
                                            approver_secret:
                                                d.approver_secret || null,
                                        }));
                                        refund.post(
                                            ReturnController.refund.url(
                                                credit.id,
                                            ),
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    <Field label="How" htmlFor="refund_tender">
                                        <NativeSelect
                                            id="refund_tender"
                                            value={refund.data.tender}
                                            onChange={(e) =>
                                                refund.setData(
                                                    'tender',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {tenders.map((t) => (
                                                <option
                                                    key={t.value}
                                                    value={t.value}
                                                >
                                                    {t.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                    <Field
                                        label="Amount"
                                        htmlFor="refund_amount"
                                        error={refund.errors.amount}
                                        hint={`Blank for all ${formatMoney(credit.remaining_cents)}.`}
                                    >
                                        <Input
                                            id="refund_amount"
                                            inputMode="decimal"
                                            value={refund.data.amount}
                                            onChange={(e) =>
                                                refund.setData(
                                                    'amount',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    {needsApproval(approval, refundCents) && (
                                        <RefundApprovalFields
                                            id="refund"
                                            approval={approval}
                                            value={{
                                                approver_id:
                                                    refund.data.approver_id,
                                                approver_secret:
                                                    refund.data.approver_secret,
                                            }}
                                            onChange={(v) =>
                                                refund.setData((d) => ({
                                                    ...d,
                                                    ...v,
                                                }))
                                            }
                                            errors={
                                                refund.errors as Record<
                                                    string,
                                                    string | undefined
                                                >
                                            }
                                        />
                                    )}
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={refund.processing}
                                    >
                                        Record refund
                                    </Button>
                                </form>
                            </Section>
                        </div>
                    )}
                </div>
            </PageBody>
        </>
    );
}

CreditNotePage.layout = {
    breadcrumbs: [
        { title: 'Returns and credits', href: ReturnController.index() },
    ],
};
