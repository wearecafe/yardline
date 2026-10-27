import { Head, Link, router } from '@inertiajs/react';
import StockLevelController from '@/actions/App/Http/Controllers/StockLevelController';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import { Chip } from '@/components/yard/chip';
import {
    Notice,
    PageBody,
    PageHeader,
    Section,
} from '@/components/yard/page-header';
import { ProductForm } from '@/components/yard/product-form';
import {
    ProductUnitsEditor,
    type EditorUnit,
} from '@/components/yard/product-units-editor';
import { formatDate, formatMoney, formatQuantity } from '@/lib/format';
import type { Option, Product } from '@/types';

type Props = {
    product: Product;
    units: Option[];
    suppliers: { id: number; name: string }[];
    sellingUnits: EditorUnit[];
    categories: string[];
    priceLists: { id: number; name: string }[];
    measures: { value: string; label: string }[];
    rounding: string;
    roundingOptions: Option[];
    stock: {
        on_hand: string;
        reorder_point: string | null;
        by_location: {
            id: number;
            name: string;
            on_hand: string;
            bin: string | null;
        }[];
        committed: string;
        base_unit: string | null;
    };
    contractPrices: {
        id: number;
        organization_id: number;
        organization: string;
        unit: string | null;
        price_cents: number;
        starts_on: string | null;
        ends_on: string | null;
        in_effect: boolean;
    }[];
    costChange: {
        changed_at: string;
        previous_cents: number;
        current_cents: number;
    } | null;
};

/**
 * The product editor (yard mockup 1): selling units and price tiers, with
 * stock, cost and customer prices alongside.
 */
export default function EditProduct(props: Props) {
    const {
        product,
        categories,
        priceLists,
        measures,
        stock,
        contractPrices,
        costChange,
    } = props;
    const editorUnits = props.sellingUnits;
    const isRental = product.kind === 'rental';
    const base = stock.base_unit ?? 'unit';
    const second = editorUnits.find((u) => !u.is_base && Number(u.factor) > 0);
    const available = Number(stock.on_hand) - Number(stock.committed);
    const marginList = priceLists[0];
    const baseRow = editorUnits.find((u) => u.is_base);
    const marginPrice = baseRow
        ? ((marginList && baseRow.tiers[String(marginList.id)]) ??
          baseRow.price_cents)
        : null;
    const newMargin =
        costChange && marginPrice
            ? Math.round(
                  ((marginPrice - costChange.current_cents) / marginPrice) *
                      100,
              )
            : null;
    const costPct = costChange
        ? Math.round(
              ((costChange.current_cents - costChange.previous_cents) /
                  costChange.previous_cents) *
                  100,
          )
        : 0;
    const overrides = new Set(
        contractPrices.filter((c) => c.in_effect).map((c) => c.organization_id),
    ).size;

    return (
        <>
            <Head title={product.name} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <>
                            <Link
                                href={ProductController.index()}
                                className="hover:underline"
                            >
                                Products
                            </Link>
                            {product.category ? ` / ${product.category}` : ''}
                        </>
                    }
                    title={product.name}
                    description={<span className="figure">{product.sku}</span>}
                />

                <div className="flex flex-wrap items-start gap-5">
                    <div className="flex min-w-0 flex-[999_1_640px] flex-col gap-5">
                        {!isRental && (
                            <ProductUnitsEditor
                                key={`${props.rounding}|${editorUnits
                                    .map(
                                        (u) =>
                                            `${u.id}:${u.price_cents}:${u.sellable}`,
                                    )
                                    .join(',')}`}
                                productId={product.id}
                                baseUnit={base}
                                units={editorUnits}
                                priceLists={priceLists}
                                measures={measures}
                                costCents={product.cost_cents}
                                rounding={props.rounding}
                                roundingOptions={props.roundingOptions}
                            />
                        )}
                        <ProductForm
                            key={product.id}
                            product={product}
                            units={props.units}
                            categories={categories}
                            suppliers={props.suppliers}
                        />
                    </div>

                    {!isRental && (
                        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-5">
                            {product.track_inventory && (
                                <Section title="On hand">
                                    <div>
                                        <div className="figure text-[28px] leading-tight font-bold">
                                            {formatQuantity(stock.on_hand)}{' '}
                                            <span className="text-lg font-semibold">
                                                {base.toLowerCase()}
                                            </span>
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            {second &&
                                                `about ${formatQuantity((Number(stock.on_hand) / Number(second.factor)).toFixed(0))} ${second.name.toLowerCase()}`}
                                            {second && stock.reorder_point
                                                ? ' · '
                                                : ''}
                                            {stock.reorder_point &&
                                                `reorder at ${formatQuantity(stock.reorder_point)}`}
                                        </div>
                                    </div>
                                    <ul className="grid gap-2 text-sm">
                                        {stock.by_location.map((l) => (
                                            <li
                                                key={l.id}
                                                className="flex items-center justify-between gap-3"
                                            >
                                                <span className="min-w-0">
                                                    {l.name}
                                                    <input
                                                        aria-label={`Bin at ${l.name}`}
                                                        defaultValue={
                                                            l.bin ?? ''
                                                        }
                                                        placeholder="Bin"
                                                        onBlur={(e) => {
                                                            if (
                                                                e.target
                                                                    .value !==
                                                                (l.bin ?? '')
                                                            ) {
                                                                router.patch(
                                                                    StockLevelController.update.url(
                                                                        l.id,
                                                                    ),
                                                                    {
                                                                        bin:
                                                                            e
                                                                                .target
                                                                                .value ||
                                                                            null,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                        className="ml-2 h-7 w-24 rounded border border-transparent bg-transparent px-1.5 text-xs text-muted-foreground hover:border-input focus:border-input"
                                                    />
                                                </span>
                                                <span className="figure">
                                                    {formatQuantity(l.on_hand)}
                                                </span>
                                            </li>
                                        ))}
                                        <li className="flex justify-between gap-3 border-t border-border pt-2">
                                            <span>
                                                Committed to open orders
                                            </span>
                                            <span className="figure">
                                                −
                                                {formatQuantity(
                                                    stock.committed,
                                                )}
                                            </span>
                                        </li>
                                        <li className="flex justify-between gap-3 font-semibold">
                                            <span>Available</span>
                                            <span className="figure">
                                                {formatQuantity(
                                                    available.toFixed(3),
                                                )}
                                            </span>
                                        </li>
                                    </ul>
                                </Section>
                            )}

                            <Section title="Cost and supplier">
                                <dl className="grid gap-2 text-sm">
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            Supplier
                                        </dt>
                                        <dd>{product.supplier_name || '—'}</dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            Landed cost
                                        </dt>
                                        <dd className="figure">
                                            {product.cost_cents !== null
                                                ? `${formatMoney(product.cost_cents)} / ${base.toLowerCase()}`
                                                : '—'}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            Tax
                                        </dt>
                                        <dd>
                                            {product.taxable === false
                                                ? 'Not taxed'
                                                : product.tax_code ||
                                                  'Standard'}
                                        </dd>
                                    </div>
                                </dl>
                                {costChange && (
                                    <Notice tone="warning">
                                        Supplier{' '}
                                        {costPct >= 0 ? 'raised' : 'cut'} cost{' '}
                                        {Math.abs(costPct)}% on{' '}
                                        {formatDate(costChange.changed_at)}.
                                        {newMargin !== null &&
                                            ` ${marginList ? `${marginList.name} margin` : 'Margin'} is now ${newMargin}%.`}{' '}
                                        Review the price tiers.
                                    </Notice>
                                )}
                            </Section>

                            <Section title="Customer prices">
                                {contractPrices.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No account has its own price on this
                                        product. Add contract prices from an
                                        organization's page.
                                    </p>
                                ) : (
                                    <>
                                        <p className="text-sm text-muted-foreground">
                                            {overrides}{' '}
                                            {overrides === 1
                                                ? 'account has its'
                                                : 'accounts have their'}{' '}
                                            own price on this product.
                                        </p>
                                        <ul className="grid gap-2.5 text-sm">
                                            {contractPrices.map((c) => (
                                                <li
                                                    key={c.id}
                                                    className="flex items-start justify-between gap-3"
                                                >
                                                    <div>
                                                        <Link
                                                            href={OrganizationController.show(
                                                                c.organization_id,
                                                            )}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {c.organization}
                                                        </Link>
                                                        <div className="text-muted-foreground">
                                                            {c.starts_on ||
                                                            c.ends_on
                                                                ? `${c.starts_on ? formatDate(c.starts_on) : 'Now'} – ${c.ends_on ? formatDate(c.ends_on) : 'ongoing'}`
                                                                : 'Ongoing'}
                                                        </div>
                                                    </div>
                                                    <div className="text-right">
                                                        <div className="figure">
                                                            {formatMoney(
                                                                c.price_cents,
                                                            )}
                                                            {c.unit
                                                                ? ` / ${c.unit.toLowerCase()}`
                                                                : ''}
                                                        </div>
                                                        {!c.in_effect && (
                                                            <Chip>
                                                                Not in effect
                                                            </Chip>
                                                        )}
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}
                            </Section>
                        </aside>
                    )}
                </div>
            </PageBody>
        </>
    );
}

EditProduct.layout = {
    breadcrumbs: [{ title: 'Products', href: ProductController.index() }],
};
