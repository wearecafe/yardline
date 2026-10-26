import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import RentalSettingsController from '@/actions/App/Http/Controllers/Settings/RentalSettingsController';
import { Field } from '@/components/yard/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type Zone = {
    id: number;
    name: string;
    postal_prefixes: string;
    fee: string;
};

/**
 * One delivery zone: postal code prefixes and a fee each way. Used for
 * material deliveries and rental drops alike.
 */
export function ZoneRow({ zone }: { zone: Zone | null }) {
    const form = useForm({
        name: zone?.name ?? '',
        postal_prefixes: zone?.postal_prefixes ?? '',
        fee: zone?.fee ?? '',
    });
    const [removing, setRemoving] = useState(false);

    return (
        <form
            className="grid gap-2 sm:grid-cols-[1fr_2fr_7rem_auto] sm:items-start"
            onSubmit={(e) => {
                e.preventDefault();

                if (zone) {
                    form.put(RentalSettingsController.updateZone.url(zone.id), {
                        preserveScroll: true,
                    });
                } else {
                    form.post(RentalSettingsController.storeZone.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }
            }}
        >
            <Field
                label="Zone"
                htmlFor={`zone-name-${zone?.id ?? 'new'}`}
                error={form.errors.name}
            >
                <Input
                    id={`zone-name-${zone?.id ?? 'new'}`}
                    required
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder="Zone 1, in town"
                />
            </Field>
            <Field
                label="Postal code prefixes"
                htmlFor={`zone-prefixes-${zone?.id ?? 'new'}`}
                error={form.errors.postal_prefixes}
            >
                <Input
                    id={`zone-prefixes-${zone?.id ?? 'new'}`}
                    value={form.data.postal_prefixes}
                    onChange={(e) =>
                        form.setData('postal_prefixes', e.target.value)
                    }
                    placeholder="L8P, L8S, L9H"
                    className="figure"
                />
            </Field>
            <Field
                label="Fee each way"
                htmlFor={`zone-fee-${zone?.id ?? 'new'}`}
                error={form.errors.fee}
            >
                <Input
                    id={`zone-fee-${zone?.id ?? 'new'}`}
                    required
                    inputMode="decimal"
                    value={form.data.fee}
                    onChange={(e) => form.setData('fee', e.target.value)}
                />
            </Field>
            <div className="flex gap-2 sm:pt-6">
                <Button
                    type="submit"
                    variant={zone ? 'outline' : 'default'}
                    disabled={form.processing}
                >
                    {zone ? 'Save' : 'Add zone'}
                </Button>
                {zone && (
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={removing}
                        onClick={() => {
                            setRemoving(true);
                            router.delete(
                                RentalSettingsController.destroyZone.url(
                                    zone.id,
                                ),
                                {
                                    preserveScroll: true,
                                    onFinish: () => setRemoving(false),
                                },
                            );
                        }}
                    >
                        Remove
                    </Button>
                )}
            </div>
        </form>
    );
}
