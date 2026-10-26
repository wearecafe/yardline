import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import BusinessController from '@/actions/App/Http/Controllers/Settings/BusinessController';
import Heading from '@/components/heading';
import { Field, NativeSelect } from '@/components/yard/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    business: {
        name: string;
        currency: string;
        timezone: string;
        stripe_account_id: string | null;
    };
    timezones: string[];
};

export default function Business({ business, timezones }: Props) {
    const form = useForm({
        name: business.name,
        timezone: business.timezone,
        stripe_account_id: business.stripe_account_id ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(BusinessController.update.url(), { preserveScroll: true });
    }

    return (
        <>
            <Head title="Business settings" />
            <h1 className="sr-only">Business settings</h1>
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Business"
                    description="Your yard business as it appears in Yardline."
                />
                <form onSubmit={submit} className="grid gap-6">
                    <Field
                        label="Business name"
                        htmlFor="name"
                        error={form.errors.name}
                    >
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field
                        label="Time zone"
                        htmlFor="timezone"
                        error={form.errors.timezone}
                    >
                        <NativeSelect
                            id="timezone"
                            value={form.data.timezone}
                            onChange={(e) =>
                                form.setData('timezone', e.target.value)
                            }
                        >
                            {timezones.map((zone) => (
                                <option key={zone} value={zone}>
                                    {zone
                                        .replace('America/', '')
                                        .replace(/_/g, ' ')}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field
                        label="Stripe connected account"
                        htmlFor="stripe_account_id"
                        error={form.errors.stripe_account_id}
                        hint="Optional. With Stripe Connect, card payments and tax go to this account (acct_…). Leave blank to use Yardline’s own Stripe account."
                    >
                        <Input
                            id="stripe_account_id"
                            value={form.data.stripe_account_id}
                            placeholder="acct_"
                            onChange={(e) =>
                                form.setData(
                                    'stripe_account_id',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                    <p className="text-sm text-muted-foreground">
                        Currency: {business.currency}
                    </p>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

Business.layout = {
    breadcrumbs: [
        { title: 'Business settings', href: BusinessController.edit() },
    ],
};
