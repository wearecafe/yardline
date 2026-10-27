import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Clock,
    Boxes,
    Factory,
    LifeBuoy,
    Plug,
    Receipt,
    Rocket,
    Search,
    ShoppingCart,
    Tag,
    Truck,
    Users,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import { dashboard, login } from '@/routes';

export type HelpNavItem = { slug: string; title: string };

export type HelpArticleSummary = {
    category: string;
    slug: string;
    title: string;
    summary: string;
};

export type HelpSharedProps = {
    nav: HelpNavItem[];
    supportEmail: string | null;
};

const icons: Record<string, LucideIcon> = {
    rocket: Rocket,
    'shopping-cart': ShoppingCart,
    tag: Tag,
    factory: Factory,
    truck: Truck,
    boxes: Boxes,
    receipt: Receipt,
    wrench: Wrench,
    plug: Plug,
    users: Users,
    clock: Clock,
};

export function CategoryIcon({
    name,
    className,
}: {
    name: string;
    className?: string;
}) {
    const Icon = icons[name] ?? LifeBuoy;

    return <Icon className={className} aria-hidden />;
}

export function articleUrl(article: { category: string; slug: string }) {
    return `/help/${article.category}/${article.slug}`;
}

export function HelpSearch({
    defaultValue = '',
    size = 'md',
}: {
    defaultValue?: string;
    size?: 'md' | 'lg';
}) {
    const id = useId();

    return (
        <form
            action="/help/search"
            method="get"
            role="search"
            className="relative w-full"
            onSubmit={(event) => {
                event.preventDefault();
                const q = new FormData(event.currentTarget).get('q');
                router.get('/help/search', {
                    q: typeof q === 'string' ? q : '',
                });
            }}
        >
            <label htmlFor={id} className="sr-only">
                Search help articles
            </label>
            <Search
                className={cn(
                    'pointer-events-none absolute top-1/2 -translate-y-1/2 text-muted-foreground',
                    size === 'lg' ? 'left-4 size-5' : 'left-3 size-4',
                )}
                aria-hidden
            />
            <input
                id={id}
                name="q"
                type="search"
                defaultValue={defaultValue}
                placeholder="Search for pallet deposits, credit limits, HST…"
                className={cn(
                    'w-full rounded-lg border border-input bg-background shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40',
                    size === 'lg'
                        ? 'h-14 pr-4 pl-12 text-base'
                        : 'h-10 pr-3 pl-9 text-sm',
                )}
            />
        </form>
    );
}

export function SupportCard({ email }: { email: string | null }) {
    if (!email) {
        return null;
    }

    return (
        <div className="flex flex-col gap-4 rounded-xl border bg-card p-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 className="font-semibold">Still stuck?</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Tell us what you were trying to do and we’ll help you get
                    there.
                </p>
            </div>
            <a
                href={`mailto:${email}`}
                className="inline-flex h-10 shrink-0 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground"
            >
                Email support
            </a>
        </div>
    );
}

export function Crumbs({
    items,
}: {
    items: { href?: string; label: string }[];
}) {
    return (
        <nav
            aria-label="Breadcrumb"
            className="flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground"
        >
            {items.map((item, i) => (
                <span key={item.label} className="flex items-center gap-1.5">
                    {i > 0 && <span aria-hidden>/</span>}
                    {item.href ? (
                        <Link
                            href={item.href}
                            className="hover:text-foreground"
                        >
                            {item.label}
                        </Link>
                    ) : (
                        <span className="text-foreground">{item.label}</span>
                    )}
                </span>
            ))}
        </nav>
    );
}

export default function HelpLayout({
    title,
    description,
    nav,
    supportEmail,
    showSearch = true,
    children,
}: HelpSharedProps & {
    title: string;
    description?: string;
    showSearch?: boolean;
    children: ReactNode;
}) {
    const { auth } = usePage().props;
    const signedIn = Boolean(auth.user);

    return (
        <>
            <Head title={title}>
                {description && (
                    <meta
                        head-key="description"
                        name="description"
                        content={description}
                    />
                )}
            </Head>
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="sticky top-0 z-20 border-b bg-background/90 backdrop-blur supports-[backdrop-filter]:bg-background/75">
                    <div className="mx-auto flex w-full max-w-6xl items-center gap-4 px-4 py-3 sm:px-6">
                        <Link
                            href="/help"
                            className="flex shrink-0 items-center gap-2"
                        >
                            <AppLogoIcon className="size-7 fill-current" />
                            <span className="text-lg font-bold tracking-tight">
                                Yardline
                            </span>
                            <span className="text-lg font-normal text-muted-foreground">
                                Help
                            </span>
                        </Link>
                        <div className="hidden flex-1 md:block">
                            {showSearch && (
                                <div className="mx-auto max-w-md">
                                    <HelpSearch />
                                </div>
                            )}
                        </div>
                        <div className="ml-auto flex items-center gap-1 text-sm sm:gap-2">
                            {showSearch && (
                                <Link
                                    href="/help/search"
                                    aria-label="Search help"
                                    className="rounded-md p-2 hover:bg-secondary md:hidden"
                                >
                                    <Search className="size-5" aria-hidden />
                                </Link>
                            )}
                            <Link
                                href="/"
                                className="hidden rounded-md px-3 py-2 hover:bg-secondary sm:block"
                            >
                                Yardline home
                            </Link>
                            {signedIn ? (
                                <Link
                                    href={dashboard()}
                                    className="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground"
                                >
                                    Open Yardline
                                </Link>
                            ) : (
                                <Link
                                    href={login()}
                                    className="rounded-md px-3 py-2 hover:bg-secondary"
                                >
                                    Log in
                                </Link>
                            )}
                        </div>
                    </div>
                </header>

                <main className="flex-1">{children}</main>

                <footer className="border-t">
                    <div className="mx-auto grid w-full max-w-6xl gap-6 px-4 py-10 text-sm sm:px-6 md:grid-cols-[1fr_2fr]">
                        <div>
                            <div className="flex items-center gap-2">
                                <AppLogoIcon className="size-5 fill-current" />
                                <span className="font-bold">Yardline Help</span>
                            </div>
                            <p className="mt-2 text-muted-foreground">
                                Guides for running your yard, counter,
                                deliveries and rentals.
                            </p>
                            {supportEmail && (
                                <a
                                    href={`mailto:${supportEmail}`}
                                    className="mt-3 inline-block text-primary hover:underline"
                                >
                                    {supportEmail}
                                </a>
                            )}
                        </div>
                        <nav
                            aria-label="Help topics"
                            className="grid gap-x-6 gap-y-2 text-muted-foreground sm:grid-cols-2"
                        >
                            {nav.map((item) => (
                                <Link
                                    key={item.slug}
                                    href={`/help/${item.slug}`}
                                    className="hover:text-foreground"
                                >
                                    {item.title}
                                </Link>
                            ))}
                        </nav>
                    </div>
                </footer>
            </div>
        </>
    );
}
