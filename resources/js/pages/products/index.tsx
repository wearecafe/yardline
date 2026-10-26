import { Head, Link, router } from '@inertiajs/react';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { Pagination } from '@/components/yard/pagination';
import { SearchBox } from '@/components/yard/search-box';
import { Button } from '@/components/ui/button';
import { formatMoney, formatQuantity, unitLabel } from '@/lib/format';
import type { Paginated, Product } from '@/types';

export default function Products({
    products,
    filters,
}: {
    products: Paginated<Product>;
    filters: { search?: string; kind?: string };
}) {
    return (
        <>
            <Head title="Products" />
            <PageBody>
                <PageHeader
                    title="Products"
                    description="Materials you sell and equipment you rent. Prices here are list prices."
                    actions={
                        <Button asChild>
                            <Link href={ProductController.create()}>
                                Add product
                            </Link>
                        </Button>
                    }
                />

                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <SearchBox
                        value={filters.search}
                        placeholder="Search name, SKU or category"
                        extra={{ kind: filters.kind }}
                    />
                    <NativeSelect
                        aria-label="Filter by type"
                        className="w-auto"
                        value={filters.kind ?? ''}
                        onChange={(e) =>
                            router.get(
                                ProductController.index.url(),
                                {
                                    search: filters.search,
                                    kind: e.target.value || undefined,
                                },
                                { preserveState: true, replace: true },
                            )
                        }
                    >
                        <option value="">Sale and rental</option>
                        <option value="sale">Materials for sale</option>
                        <option value="rental">Rental equipment</option>
                    </NativeSelect>
                </div>

                <Table>
                    <thead>
                        <tr>
                            <Th>SKU</Th>
                            <Th>Name</Th>
                            <Th>Category</Th>
                            <Th numeric>Price</Th>
                            <Th numeric>On hand</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {products.data.length === 0 && (
                            <EmptyRow colSpan={5}>
                                No products match. Add one, or clear the search.
                            </EmptyRow>
                        )}
                        {products.data.map((product) => (
                            <tr
                                key={product.id}
                                className={
                                    product.is_active
                                        ? 'hover:bg-accent/50'
                                        : 'text-muted-foreground'
                                }
                            >
                                <Td className="whitespace-nowrap text-muted-foreground">
                                    {product.sku}
                                </Td>
                                <Td>
                                    <Link
                                        href={ProductController.edit(
                                            product.id,
                                        )}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {product.name}
                                    </Link>
                                    {product.kind === 'rental' && (
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            Rental
                                        </span>
                                    )}
                                    {!product.is_active && (
                                        <span className="ml-2 text-xs">
                                            Inactive
                                        </span>
                                    )}
                                </Td>
                                <Td className="text-muted-foreground">
                                    {product.category}
                                </Td>
                                <Td numeric>
                                    {formatMoney(product.price_cents)}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        /{' '}
                                        {product.kind === 'rental'
                                            ? product.rental_period
                                            : unitLabel(product.unit)}
                                    </span>
                                </Td>
                                <Td numeric>
                                    {product.track_inventory
                                        ? formatQuantity(product.on_hand ?? '0')
                                        : 'Not tracked'}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
                <Pagination page={products} />
            </PageBody>
        </>
    );
}

Products.layout = {
    breadcrumbs: [{ title: 'Products', href: ProductController.index() }],
};
