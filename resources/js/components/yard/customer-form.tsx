import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import { Field, NativeSelect } from '@/components/yard/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Customer } from '@/types';

export function CustomerForm({
    customer,
    organizations,
    organizationId,
}: {
    customer?: Customer;
    organizations: { id: number; name: string; account_number: string }[];
    organizationId?: number | null;
}) {
    const form = useForm({
        organization_id: String(
            customer?.organization_id ?? organizationId ?? '',
        ),
        first_name: customer?.first_name ?? '',
        last_name: customer?.last_name ?? '',
        email: customer?.email ?? '',
        phone: customer?.phone ?? '',
        notes: customer?.notes ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (customer) {
            form.put(CustomerController.update.url(customer.id));
        } else {
            form.post(CustomerController.store.url());
        }
    }

    return (
        <form onSubmit={submit} className="grid max-w-2xl gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="First name"
                    htmlFor="first_name"
                    error={form.errors.first_name}
                >
                    <Input
                        id="first_name"
                        value={form.data.first_name}
                        onChange={(e) =>
                            form.setData('first_name', e.target.value)
                        }
                        required
                        autoFocus={!customer}
                    />
                </Field>
                <Field
                    label="Last name"
                    htmlFor="last_name"
                    error={form.errors.last_name}
                >
                    <Input
                        id="last_name"
                        value={form.data.last_name}
                        onChange={(e) =>
                            form.setData('last_name', e.target.value)
                        }
                    />
                </Field>
                <Field label="Phone" htmlFor="phone" error={form.errors.phone}>
                    <Input
                        id="phone"
                        type="tel"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                    />
                </Field>
                <Field label="Email" htmlFor="email" error={form.errors.email}>
                    <Input
                        id="email"
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </Field>
            </div>
            <Field
                label="Organization"
                htmlFor="organization_id"
                error={form.errors.organization_id}
                hint="Leave blank for walk-in customers."
            >
                <NativeSelect
                    id="organization_id"
                    value={form.data.organization_id}
                    onChange={(e) =>
                        form.setData('organization_id', e.target.value)
                    }
                >
                    <option value="">None (walk-in)</option>
                    {organizations.map((org) => (
                        <option key={org.id} value={org.id}>
                            {org.name} ({org.account_number})
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Field label="Notes" htmlFor="notes" error={form.errors.notes}>
                <textarea
                    id="notes"
                    rows={3}
                    className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm"
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
            </Field>
            <div>
                <Button type="submit" disabled={form.processing}>
                    {customer ? 'Save customer' : 'Add customer'}
                </Button>
            </div>
        </form>
    );
}
