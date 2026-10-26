import { Head, Link, router, useForm } from '@inertiajs/react';
import CycleCountController from '@/actions/App/Http/Controllers/CycleCountController';
import InventoryController from '@/actions/App/Http/Controllers/InventoryController';
import { Table, Td, Th } from '@/components/yard/data-table';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { formatDate, formatQuantity, unitLabel } from '@/lib/format';
import { cn } from '@/lib/utils';

type Props = {
    count: {
        id: number;
        name: string;
        status: string;
        started_by: string | null;
        created_at: string;
        completed_at: string | null;
        lines: {
            id: number;
            name: string;
            sku: string;
            unit: string;
            bin: string | null;
            expected: string;
            counted: string | null;
        }[];
    };
};

/**
 * A cycle count sheet: walk the bins, enter what's there, post it.
 */
export default function Count({ count }: Props) {
    const open = count.status === 'open';
    const form = useForm<{ counts: Record<string, string> }>({
        counts: Object.fromEntries(
            count.lines.map((l) => [
                String(l.id),
                l.counted === null ? '' : String(Number(l.counted)),
            ]),
        ),
    });
    const counted = count.lines.filter(
        (l) => form.data.counts[String(l.id)] !== '',
    ).length;

    return (
        <>
            <Head title={`Count · ${count.name}`} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <Link
                            href={InventoryController.index()}
                            className="hover:underline"
                        >
                            Stock
                        </Link>
                    }
                    title={`Cycle count: ${count.name}`}
                    description={`Started ${formatDate(count.created_at)}${count.started_by ? ` by ${count.started_by}` : ''} · ${counted} of ${count.lines.length} counted${count.completed_at ? ` · posted ${formatDate(count.completed_at)}` : ''}`}
                    actions={
                        open && (
                            <>
                                <Button
                                    variant="outline"
                                    disabled={form.processing}
                                    onClick={() =>
                                        form.put(
                                            CycleCountController.update.url(
                                                count.id,
                                            ),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Save progress
                                </Button>
                                <Button
                                    disabled={form.processing || counted === 0}
                                    onClick={() =>
                                        form.put(
                                            CycleCountController.update.url(
                                                count.id,
                                            ),
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    router.post(
                                                        CycleCountController.complete.url(
                                                            count.id,
                                                        ),
                                                    ),
                                            },
                                        )
                                    }
                                >
                                    Post count
                                </Button>
                            </>
                        )
                    }
                />
                <Section flush>
                    <Table minWidth={640}>
                        <thead>
                            <tr>
                                <Th>Bin</Th>
                                <Th>Product</Th>
                                <Th numeric>Expected</Th>
                                <Th numeric>Counted</Th>
                                <Th numeric>Difference</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {count.lines.map((l) => {
                                const value = form.data.counts[String(l.id)];
                                const diff =
                                    value === ''
                                        ? null
                                        : Number(value) - Number(l.expected);

                                return (
                                    <tr key={l.id}>
                                        <Td className="figure">
                                            {l.bin ?? '—'}
                                        </Td>
                                        <Td>
                                            <div className="font-medium">
                                                {l.name}
                                            </div>
                                            <div className="figure text-xs text-muted-foreground">
                                                {l.sku}
                                            </div>
                                        </Td>
                                        <Td numeric className="text-subtle">
                                            {formatQuantity(l.expected)}{' '}
                                            {unitLabel(l.unit)}
                                        </Td>
                                        <Td numeric>
                                            <input
                                                aria-label={`${l.name} counted`}
                                                inputMode="decimal"
                                                disabled={!open}
                                                value={value}
                                                onChange={(e) =>
                                                    form.setData('counts', {
                                                        ...form.data.counts,
                                                        [String(l.id)]:
                                                            e.target.value,
                                                    })
                                                }
                                                className="figure h-10 w-24 rounded-lg border border-input bg-card px-2 text-right"
                                            />
                                        </Td>
                                        <Td
                                            numeric
                                            className={cn(
                                                diff !== null &&
                                                    diff < 0 &&
                                                    'text-danger',
                                                diff !== null &&
                                                    diff > 0 &&
                                                    'text-success',
                                            )}
                                        >
                                            {diff === null
                                                ? '—'
                                                : `${diff > 0 ? '+' : ''}${formatQuantity(diff.toFixed(3))}`}
                                        </Td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </Table>
                </Section>
            </PageBody>
        </>
    );
}

Count.layout = {
    breadcrumbs: [{ title: 'Stock', href: InventoryController.index() }],
};
