import { Link, router } from '@inertiajs/react';
import { Mail, PackagePlus } from 'lucide-react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import { StepActions, StepHeader } from '@/components/setup/step';
import { Notice } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type Props = {
    productCount: number;
    supportEmail: string | null;
};

const UNITS = [
    ['Base unit', 'What stock is counted in', 'Bag'],
    ['Pallet', 'Sold as 56 bags', '56 bags'],
    ['Layer', 'Sold as 8 bags', '8 bags'],
] as const;

export default function SetupProducts({ productCount, supportEmail }: Props) {
    return (
        <>
            <StepHeader description="Everything you sell, in the units you sell it in. Stock is counted in one base unit, and every pallet, layer or tonne converts back to it." />

            <div className="grid gap-6">
                {productCount > 0 && (
                    <Notice tone="success">
                        You have {productCount}{' '}
                        {productCount === 1 ? 'product' : 'products'} so far.
                    </Notice>
                )}

                <div className="rounded-[10px] border border-border bg-card p-5">
                    <p className="text-sm font-semibold">
                        How a product sells: 30 kg polymeric sand
                    </p>
                    <table className="mt-3 w-full text-sm">
                        <tbody className="divide-y divide-border">
                            {UNITS.map(([unit, what, size]) => (
                                <tr key={unit}>
                                    <td className="py-2 pr-3 font-medium">
                                        {unit}
                                    </td>
                                    <td className="py-2 pr-3 text-muted-foreground">
                                        {what}
                                    </td>
                                    <td className="figure py-2 text-right">
                                        {size}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="mt-3 text-sm text-muted-foreground">
                        Each unit gets its own price, and price lists can give
                        contractors a better one.{' '}
                        <a
                            href="/help/products-pricing/selling-units"
                            target="_blank"
                            rel="noreferrer"
                            className="font-medium text-foreground underline underline-offset-4"
                        >
                            How selling units work
                        </a>
                    </p>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="flex flex-col gap-3 rounded-[10px] border border-border bg-card p-5">
                        <PackagePlus className="size-5 text-primary" />
                        <div>
                            <p className="font-semibold">Add them yourself</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Start with your ten best sellers. You can add
                                the rest as they come up at the counter.
                            </p>
                        </div>
                        <Button variant="outline" className="mt-auto" asChild>
                            <Link href={ProductController.create()}>
                                Add a product
                            </Link>
                        </Button>
                    </div>
                    {supportEmail && (
                        <div className="flex flex-col gap-3 rounded-[10px] border border-border bg-card p-5">
                            <Mail className="size-5 text-primary" />
                            <div>
                                <p className="font-semibold">
                                    Send us your price list
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    A spreadsheet, a PDF or an export from your
                                    old system. We’ll load it for you, free in
                                    your trial.
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                className="mt-auto"
                                asChild
                            >
                                <a
                                    href={`mailto:${supportEmail}?subject=${encodeURIComponent('Please load our price list')}`}
                                >
                                    Email it to us
                                </a>
                            </Button>
                        </div>
                    )}
                </div>

                <p className="text-sm text-muted-foreground">
                    Not ready? It stays on your checklist on{' '}
                    <Link
                        href={dashboard()}
                        className="underline underline-offset-4"
                    >
                        Today
                    </Link>
                    .
                </p>
            </div>

            <StepActions
                primary="Continue"
                skip={false}
                onPrimary={() =>
                    router.post(SetupController.next.url({ step: 'products' }))
                }
            />
        </>
    );
}
