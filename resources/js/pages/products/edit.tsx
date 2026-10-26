import { Head } from '@inertiajs/react';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { ProductForm } from '@/components/yard/product-form';
import type { Option, Product } from '@/types';

export default function EditProduct({
    product,
    units,
    categories,
}: {
    product: Product;
    units: Option[];
    categories: string[];
}) {
    return (
        <>
            <Head title={product.name} />
            <PageBody>
                <PageHeader title={product.name} description={product.sku} />
                <ProductForm
                    key={product.id}
                    product={product}
                    units={units}
                    categories={categories}
                />
            </PageBody>
        </>
    );
}

EditProduct.layout = {
    breadcrumbs: [{ title: 'Products', href: ProductController.index() }],
};
