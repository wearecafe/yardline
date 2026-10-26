import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PriceListController from '@/actions/App/Http/Controllers/PriceListController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { PriceListSummary } from '@/types';

export default function PriceLists({
    priceLists,
}: {
    priceLists: PriceListSummary[];
}) {
    const form = useForm({ name: '', description: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(PriceListController.store.url());
    }

    return (
        <>
            <Head title="Price lists" />
            <PageBody>
                <PageHeader
                    title="Price lists"
                    description="Trade pricing. Give an organization a price list and its orders use these prices instead of list price."
                />
                <div className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Table>
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th numeric>Prices</Th>
                                <Th numeric>Organizations</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {priceLists.length === 0 && (
                                <EmptyRow colSpan={3}>
                                    No price lists yet. Everyone pays list
                                    price.
                                </EmptyRow>
                            )}
                            {priceLists.map((list) => (
                                <tr
                                    key={list.id}
                                    className="hover:bg-accent/50"
                                >
                                    <Td>
                                        <Link
                                            href={PriceListController.edit(
                                                list.id,
                                            )}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {list.name}
                                        </Link>
                                        {list.description && (
                                            <div className="text-xs text-muted-foreground">
                                                {list.description}
                                            </div>
                                        )}
                                    </Td>
                                    <Td numeric>{list.items_count}</Td>
                                    <Td numeric>{list.organizations_count}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>

                    <Section title="New price list">
                        <form onSubmit={submit} className="grid gap-4">
                            <Field
                                label="Name"
                                htmlFor="name"
                                error={form.errors.name}
                            >
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    placeholder="e.g. Contractor"
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                label="Description"
                                htmlFor="description"
                                error={form.errors.description}
                            >
                                <Input
                                    id="description"
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <div>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    Create price list
                                </Button>
                            </div>
                        </form>
                    </Section>
                </div>
            </PageBody>
        </>
    );
}

PriceLists.layout = {
    breadcrumbs: [{ title: 'Price lists', href: PriceListController.index() }],
};
