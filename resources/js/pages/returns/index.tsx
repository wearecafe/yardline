import { Head, Link, useForm } from '@inertiajs/react';
import ReturnController from '@/actions/App/Http/Controllers/ReturnController';
import { Chip } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Pagination } from '@/components/yard/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatMoney } from '@/lib/format';
import type { Paginated } from '@/types';

type Credit = {
    id: number;
    number: string;
    kind: 'return' | 'pallets' | 'adjustment';
    who: string;
    order_number: string | null;
    reason: string | null;
    issued_on: string;
    total_cents: number;
    remaining_cents: number;
    status: string;
};

type Props = {
    credits: Paginated<Credit>;
    organizations: { id: number; name: string; pallets_out: number }[];
    customers: { id: number; name: string; pallets_out: number }[];
    locations: { id: number; name: string }[];
    settings: {
        restocking_percent: number;
        pallet_deposits: boolean;
        pallet_deposit_cents: number;
        return_window_days: number;
    };
    palletsOut: number;
};

const kinds: Record<Credit['kind'], string> = {
    return: 'Return',
    pallets: 'Pallets',
    adjustment: 'Adjustment',
};

/**
 * Returns and credits: credit notes, and pallets coming back. Returns of
 * material start from the order.
 */
export default function Returns({
    credits,
    organizations,
    customers,
    locations,
    settings,
    palletsOut,
}: Props) {
    const form = useForm({
        who: '',
        count: '',
        location_id: String(locations[0]?.id ?? ''),
    });
    const [kind, id] = form.data.who.split(':');
    const picked =
        kind === 'o'
            ? organizations.find((o) => String(o.id) === id)
            : customers.find((c) => String(c.id) === id);

    return (
        <>
            <Head title="Returns and credits" />
            <PageBody>
                <PageHeader
                    title="Returns and credits"
                    description={`Returns start from the order. Restocking fee ${settings.restocking_percent}%. ${palletsOut} pallets out on deposit.`}
                />
                <div className="flex flex-wrap items-start gap-5">
                    <Section
                        title="Credit notes"
                        flush
                        className="min-w-0 flex-[999_1_560px]"
                    >
                        <Table minWidth={640}>
                            <thead>
                                <tr>
                                    <Th>Credit</Th>
                                    <Th>For</Th>
                                    <Th>Why</Th>
                                    <Th numeric>Amount</Th>
                                    <Th numeric>Unused</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {credits.data.length === 0 && (
                                    <EmptyRow colSpan={5}>
                                        No credit notes yet.
                                    </EmptyRow>
                                )}
                                {credits.data.map((c) => (
                                    <tr key={c.id}>
                                        <Td>
                                            <Link
                                                href={ReturnController.show(
                                                    c.id,
                                                )}
                                                className="figure font-medium hover:underline"
                                            >
                                                {c.number}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {formatDate(c.issued_on)}
                                            </div>
                                        </Td>
                                        <Td>
                                            {c.who}
                                            {c.order_number && (
                                                <div className="figure text-xs text-muted-foreground">
                                                    {c.order_number}
                                                </div>
                                            )}
                                        </Td>
                                        <Td>
                                            <Chip>{kinds[c.kind]}</Chip>
                                            {c.reason && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {c.reason}
                                                </div>
                                            )}
                                        </Td>
                                        <Td numeric>
                                            {formatMoney(c.total_cents)}
                                        </Td>
                                        <Td
                                            numeric
                                            className={
                                                c.remaining_cents
                                                    ? 'font-semibold'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {c.remaining_cents
                                                ? formatMoney(c.remaining_cents)
                                                : 'Used'}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Section>

                    <Section
                        title="Pallets back"
                        description={
                            settings.pallet_deposits
                                ? `Credit ${formatMoney(settings.pallet_deposit_cents)} a pallet.`
                                : 'Pallet deposits are off.'
                        }
                        className="min-w-0 flex-[1_1_300px]"
                    >
                        <form
                            className="grid gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.transform((d) => ({
                                    organization_id:
                                        kind === 'o' ? Number(id) : null,
                                    customer_id:
                                        kind === 'c' ? Number(id) : null,
                                    count: d.count,
                                    location_id: d.location_id,
                                }));
                                form.post(ReturnController.pallets.url());
                            }}
                        >
                            <Field
                                label="From"
                                htmlFor="pallet_who"
                                error={
                                    (form.errors as Record<string, string>)
                                        .organization_id
                                }
                            >
                                <NativeSelect
                                    id="pallet_who"
                                    required
                                    value={form.data.who}
                                    onChange={(e) =>
                                        form.setData('who', e.target.value)
                                    }
                                >
                                    <option value="">Pick an account</option>
                                    <optgroup label="Accounts">
                                        {organizations
                                            .filter((o) => o.pallets_out > 0)
                                            .map((o) => (
                                                <option
                                                    key={o.id}
                                                    value={`o:${o.id}`}
                                                >
                                                    {o.name} ({o.pallets_out}{' '}
                                                    out)
                                                </option>
                                            ))}
                                    </optgroup>
                                    <optgroup label="Walk-ins">
                                        {customers
                                            .filter((c) => c.pallets_out > 0)
                                            .map((c) => (
                                                <option
                                                    key={c.id}
                                                    value={`c:${c.id}`}
                                                >
                                                    {c.name} ({c.pallets_out}{' '}
                                                    out)
                                                </option>
                                            ))}
                                    </optgroup>
                                </NativeSelect>
                            </Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Field
                                    label="Pallets"
                                    htmlFor="pallet_count"
                                    error={form.errors.count}
                                >
                                    <Input
                                        id="pallet_count"
                                        required
                                        inputMode="numeric"
                                        value={form.data.count}
                                        max={picked?.pallets_out}
                                        onChange={(e) =>
                                            form.setData(
                                                'count',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field label="Yard" htmlFor="pallet_yard">
                                    <NativeSelect
                                        id="pallet_yard"
                                        value={form.data.location_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'location_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {locations.map((l) => (
                                            <option key={l.id} value={l.id}>
                                                {l.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </div>
                            {picked && form.data.count && (
                                <p className="text-sm text-muted-foreground">
                                    Credit{' '}
                                    {formatMoney(
                                        Number(form.data.count) *
                                            settings.pallet_deposit_cents,
                                    )}{' '}
                                    to {picked.name}.
                                </p>
                            )}
                            <Button
                                type="submit"
                                disabled={
                                    form.processing || !settings.pallet_deposits
                                }
                            >
                                Credit pallets
                            </Button>
                        </form>
                    </Section>
                </div>
                <Pagination page={credits} />
            </PageBody>
        </>
    );
}

Returns.layout = {
    breadcrumbs: [
        { title: 'Returns and credits', href: ReturnController.index() },
    ],
};
