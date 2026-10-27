import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PriceListController from '@/actions/App/Http/Controllers/PriceListController';
import PricingSettingsController from '@/actions/App/Http/Controllers/Settings/PricingSettingsController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Checkbox, Field } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { PriceListSummary } from '@/types';

type Pricing = {
    category_rules_override: boolean;
    exempt_price_roles: string[];
    roles: { value: string; label: string }[];
};

export default function PriceLists({
    priceLists,
    pricing,
}: {
    priceLists: PriceListSummary[];
    pricing: Pricing;
}) {
    const { auth } = usePage().props;
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
                                <Th numeric>Category discounts</Th>
                                <Th numeric>Organizations</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {priceLists.length === 0 && (
                                <EmptyRow colSpan={4}>
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
                                    <Td numeric>{list.category_rules_count}</Td>
                                    <Td numeric>{list.organizations_count}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>

                    <div className="grid content-start gap-8">
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
                        {auth.can.manageBusiness && (
                            <PricingRules pricing={pricing} />
                        )}
                    </div>
                </div>
            </PageBody>
        </>
    );
}

function PricingRules({ pricing }: { pricing: Pricing }) {
    const form = useForm({
        category_rules_override: pricing.category_rules_override,
        exempt_price_roles: pricing.exempt_price_roles,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(PricingSettingsController.update.url(), {
            preserveScroll: true,
        });
    }

    function toggleRole(role: string, on: boolean) {
        form.setData(
            'exempt_price_roles',
            on
                ? [...form.data.exempt_price_roles, role]
                : form.data.exempt_price_roles.filter((r) => r !== role),
        );
    }

    return (
        <Section title="Pricing rules">
            <form onSubmit={submit} className="grid gap-4">
                <Checkbox
                    id="category_rules_override"
                    label="Category discounts beat product prices"
                    hint="The default for price lists that don't choose. Off: a product's own price on a list wins over its category discount."
                    checked={form.data.category_rules_override}
                    onChange={(checked) =>
                        form.setData('category_rules_override', checked)
                    }
                />
                <fieldset className="grid gap-2">
                    <legend className="mb-1 text-sm font-medium">
                        Who can change the price of an exempt product
                    </legend>
                    {pricing.roles.map((role) => (
                        <Checkbox
                            key={role.value}
                            id={`exempt_role_${role.value}`}
                            label={role.label}
                            checked={form.data.exempt_price_roles.includes(
                                role.value,
                            )}
                            onChange={(checked) =>
                                toggleRole(role.value, checked)
                            }
                        />
                    ))}
                    <p className="text-xs text-muted-foreground">
                        They give a reason, which goes in the audit log. Staff
                        can't change line prices at all.
                    </p>
                    {form.errors.exempt_price_roles && (
                        <p className="text-sm text-destructive">
                            {form.errors.exempt_price_roles}
                        </p>
                    )}
                </fieldset>
                <div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={form.processing}
                    >
                        Save pricing rules
                    </Button>
                </div>
            </form>
        </Section>
    );
}

PriceLists.layout = {
    breadcrumbs: [{ title: 'Price lists', href: PriceListController.index() }],
};
