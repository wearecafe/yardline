import { Head, Link, useForm } from '@inertiajs/react';
import TenantController from '@/actions/App/Http/Controllers/Admin/TenantController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';

type Props = {
    plans: { value: string; label: string }[];
    statuses: { value: 'trial' | 'active'; label: string }[];
    defaultTrialEnds: string;
};

export default function CreateTenant({
    plans,
    statuses,
    defaultTrialEnds,
}: Props) {
    const form = useForm({
        name: '',
        owner_name: '',
        owner_email: '',
        status: 'trial' as 'trial' | 'active',
        plan: plans[0]?.value ?? '',
        monthly_price: '',
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
                        title="Plan"
                        description="Recorded for reporting and MRR. Nothing is charged from here."
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Status"
                                htmlFor="status"
                                error={form.errors.status}
                            >
                                <NativeSelect
                                    id="status"
                                    value={form.data.status}
                                    onChange={(e) =>
                                        form.setData(
                                            'status',
                                            e.target.value as
                                                | 'trial'
                                                | 'active',
                                        )
                                    }
                                >
                                    {statuses.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            {form.data.status === 'trial' && (
                                <Field
                                    label="Trial ends"
                                    htmlFor="trial_ends_at"
                                    error={form.errors.trial_ends_at}
                                >
                                    <Input
                                        id="trial_ends_at"
                                        type="date"
                                        value={form.data.trial_ends_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'trial_ends_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            )}
                            <Field
                                label="Plan"
                                htmlFor="plan"
                                error={form.errors.plan}
                            >
                                <NativeSelect
                                    id="plan"
                                    value={form.data.plan}
                                    onChange={(e) =>
                                        form.setData('plan', e.target.value)
                                    }
                                >
                                    <option value="">Not set</option>
                                    {plans.map((p) => (
                                        <option key={p.value} value={p.value}>
                                            {p.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="Monthly price (CAD)"
                                htmlFor="monthly_price"
                                error={form.errors.monthly_price}
                                hint="Before tax. Leave blank if not agreed yet."
                            >
                                <Input
                                    id="monthly_price"
                                    inputMode="decimal"
                                    value={form.data.monthly_price}
                                    onChange={(e) =>
                                        form.setData(
                                            'monthly_price',
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
