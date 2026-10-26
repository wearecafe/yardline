import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import {
    headerPayload,
    OrderHeaderFields,
} from '@/components/yard/order-header-fields';
import type { OrderHeaderData } from '@/components/yard/order-header-fields';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';

type Props = {
    type: 'sale' | 'rental';
    organizationId: number | null;
    organizations: { id: number; name: string; account_number: string }[];
    customers: {
        id: number;
        organization_id: number | null;
        first_name: string;
        last_name: string | null;
    }[];
    locations: { id: number; name: string; is_default: boolean }[];
};

export default function CreateOrder({
    type,
    organizationId,
    organizations,
    customers,
    locations,
}: Props) {
    const isRental = type === 'rental';
    const form = useForm<OrderHeaderData>({
        organization_id: organizationId ? String(organizationId) : '',
        customer_id: '',
        organization_location_id: '',
        location_id: String(
            locations.find((l) => l.is_default)?.id ?? locations[0]?.id ?? '',
        ),
        fulfillment: 'pickup',
        delivery_address: {},
        requested_on: '',
        po_number: '',
        rental_starts_at: '',
        rental_ends_at: '',
        notes: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ type, ...headerPayload(data, isRental) }));
        form.post(OrderController.store.url());
    }

    return (
        <>
            <Head title={isRental ? 'New rental' : 'New order'} />
            <PageBody>
                <PageHeader
                    title={isRental ? 'New rental' : 'New order'}
                    description={
                        isRental
                            ? 'Rentals are prepaid with a deposit and kept separate from material orders.'
                            : 'Start with who it’s for. You’ll add items next.'
                    }
                />
                <form onSubmit={submit} className="grid max-w-3xl gap-6">
                    <OrderHeaderFields
                        form={form}
                        isRental={isRental}
                        organizations={organizations}
                        customers={customers}
                        locations={locations}
                    />
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Start order
                        </Button>
                    </div>
                </form>
            </PageBody>
        </>
    );
}

CreateOrder.layout = {
    breadcrumbs: [
        { title: 'Orders', href: OrderController.index() },
        { title: 'New', href: OrderController.create() },
    ],
};
