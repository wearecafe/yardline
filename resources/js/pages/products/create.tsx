import { Head } from '@inertiajs/react';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { ProductForm } from '@/components/yard/product-form';
import type { Option } from '@/types';

export default function CreateProduct({
    units,
    categories,
}: {
    units: Option[];
    categories: string[];
}) {
    return (
        <>
            <Head title="New product" />
            <PageBody>
                <PageHeader title="New product" />
                <ProductForm units={units} categories={categories} />
            </PageBody>
        </>
    );
}

CreateProduct.layout = {
    breadcrumbs: [
        { title: 'Products', href: ProductController.index() },
        { title: 'New', href: ProductController.create() },
    ],
};
