import { Head, Link, useForm } from '@inertiajs/react';
import TenantController from '@/actions/App/Http/Controllers/Admin/TenantController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';

type Props = {
    defaultTrialEnds: string;
};

export default function CreateTenant({ defaultTrialEnds }: Props) {
    const form = useForm({
        name: '',
        owner_name: '',
        owner_email: '',
        trial_ends_at: defaultTrialEnds,
        timezone: 'America/Toronto',
    });

    return (
        <>
            <Head title="New tenant" />
            <PageBody className="max-w-3xl">
                <PageHeader
                    eyebrow={
                        <Link
                            href={TenantController.index()}
                            className="hover:underline"
                        >
                            Tenants
                        </Link>
                    }
                    title="New tenant"
                    description="Sets up the business with a first yard, and emails the owner a link to choose a password."
                />

                <form
                    className="flex flex-col gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(TenantController.store.url());
                    }}
                >
                    <Section title="Business">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Business name"
                                htmlFor="name"
                                error={form.errors.name}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="name"
                                    required
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Owner’s name"
                                htmlFor="owner_name"
                                error={form.errors.owner_name}
                            >
                                <Input
                                    id="owner_name"
                                    required
                                    value={form.data.owner_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'owner_name',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Owner’s email"
                                htmlFor="owner_email"
                                error={form.errors.owner_email}
                            >
                                <Input
                                    id="owner_email"
                                    type="email"
                                    required
                                    value={form.data.owner_email}
                                    onChange={(e) =>
                                        form.setData(
                                            'owner_email',
                                            e.target.value,
                                        )
                                    }
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
                                    <option value="America/Toronto">
                                        Eastern (Toronto, Montréal)
                                    </option>
                                    <option value="America/Halifax">
                                        Atlantic (Halifax)
                                    </option>
                                    <option value="America/Winnipeg">
                                        Central (Winnipeg)
                                    </option>
                                    <option value="America/Edmonton">
                                        Mountain (Edmonton)
                                    </option>
                                    <option value="America/Vancouver">
                                        Pacific (Vancouver)
                                    </option>
                                </NativeSelect>
                            </Field>
                        </div>
                    </Section>

                    <Section
                        title="Free trial"
                        description="They can use everything, add-ons included, until the trial ends, then add payment details under Settings → Billing."
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Last day of trial"
                                htmlFor="trial_ends_at"
                                error={form.errors.trial_ends_at}
                            >
                                <Input
                                    id="trial_ends_at"
                                    type="date"
                                    required
                                    value={form.data.trial_ends_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'trial_ends_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    </Section>

                    <div className="flex flex-wrap gap-2.5">
                        <Button type="submit" disabled={form.processing}>
                            Set up tenant
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={TenantController.index()}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageBody>
        </>
    );
}
