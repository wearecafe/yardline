import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import LocationController from '@/actions/App/Http/Controllers/LocationController';
import { Checkbox, Field } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Location } from '@/types';

function LocationForm({
    location,
    onDone,
}: {
    location?: Location;
    onDone?: () => void;
}) {
    const form = useForm({
        name: location?.name ?? '',
        code: location?.code ?? '',
        address_line1: location?.address_line1 ?? '',
        address_line2: location?.address_line2 ?? '',
        city: location?.city ?? '',
        region: location?.region ?? 'ON',
        postal_code: location?.postal_code ?? '',
        country: location?.country ?? 'CA',
        is_default: location?.is_default ?? false,
    });
    const id = (field: string) => `${location?.id ?? 'new'}-${field}`;

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                if (!location) {
                    form.reset();
                }

                onDone?.();
            },
        };

        if (location) {
            form.put(LocationController.update.url(location.id), options);
        } else {
            form.post(LocationController.store.url(), options);
        }
    }

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="grid grid-cols-[1fr_7rem] gap-4">
                <Field
                    label="Name"
                    htmlFor={id('name')}
                    error={form.errors.name}
                >
                    <Input
                        id={id('name')}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                    />
                </Field>
                <Field
                    label="Code"
                    htmlFor={id('code')}
                    error={form.errors.code}
                >
                    <Input
                        id={id('code')}
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value)}
                        required
                    />
                </Field>
            </div>
            <Field
                label="Street"
                htmlFor={id('line1')}
                error={form.errors.address_line1}
                hint="Pickup orders are taxed at this address."
            >
                <Input
                    id={id('line1')}
                    value={form.data.address_line1}
                    onChange={(e) =>
                        form.setData('address_line1', e.target.value)
                    }
                />
            </Field>
            <div className="grid grid-cols-[1fr_5rem_7rem] gap-4">
                <Field
                    label="City"
                    htmlFor={id('city')}
                    error={form.errors.city}
                >
                    <Input
                        id={id('city')}
                        value={form.data.city}
                        onChange={(e) => form.setData('city', e.target.value)}
                    />
                </Field>
                <Field
                    label="Province"
                    htmlFor={id('region')}
                    error={form.errors.region}
                >
                    <Input
                        id={id('region')}
                        value={form.data.region}
                        onChange={(e) => form.setData('region', e.target.value)}
                    />
                </Field>
                <Field
                    label="Postal code"
                    htmlFor={id('postal')}
                    error={form.errors.postal_code}
                >
                    <Input
                        id={id('postal')}
                        value={form.data.postal_code}
                        onChange={(e) =>
                            form.setData('postal_code', e.target.value)
                        }
                    />
                </Field>
            </div>
            <Checkbox
                id={id('default')}
                label="Default yard for new orders"
                checked={form.data.is_default}
                onChange={(checked) => form.setData('is_default', checked)}
            />
            <div>
                <Button type="submit" disabled={form.processing}>
                    {location ? 'Save yard' : 'Add yard'}
                </Button>
            </div>
        </form>
    );
}

export default function Locations({ locations }: { locations: Location[] }) {
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <>
            <Head title="Yards" />
            <PageBody>
                <PageHeader
                    title="Yards"
                    description="Each yard keeps its own stock. Orders are filled from one yard."
                />
                <div className="grid gap-8 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <ul className="divide-y divide-border rounded-md border border-border bg-card">
                        {locations.map((location) => (
                            <li key={location.id} className="p-4">
                                {editing === location.id ? (
                                    <LocationForm
                                        location={location}
                                        onDone={() => setEditing(null)}
                                    />
                                ) : (
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <div className="font-medium">
                                                {location.name}{' '}
                                                <span className="text-muted-foreground">
                                                    {location.code}
                                                </span>
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                {[
                                                    location.address_line1,
                                                    location.city,
                                                    location.region,
                                                    location.postal_code,
                                                ]
                                                    .filter(Boolean)
                                                    .join(', ') ||
                                                    'No address yet. Add one so pickup orders can be taxed.'}
                                            </div>
                                            {location.is_default && (
                                                <div className="mt-1 text-xs font-medium">
                                                    Default yard
                                                </div>
                                            )}
                                        </div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setEditing(location.id)
                                            }
                                        >
                                            Edit
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                    <Section title="Add a yard">
                        <LocationForm />
                    </Section>
                </div>
            </PageBody>
        </>
    );
}

Locations.layout = {
    breadcrumbs: [{ title: 'Yards', href: LocationController.index() }],
};
