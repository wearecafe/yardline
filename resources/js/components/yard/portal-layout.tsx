import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import PortalController from '@/actions/App/Http/Controllers/Portal/PortalController';
import PortalAuthController from '@/actions/App/Http/Controllers/Portal/PortalAuthController';
import { cn } from '@/lib/utils';

type Portal = {
    customer: { id: number; name: string; first_name: string };
    organization: { id: number; name: string; account_number: string } | null;
    yard: { name: string; slug: string };
};

/**
 * The contractor portal's frame: the yard's name, four places to go, and
 * the account it's for.
 */
export function PortalLayout({
    children,
    active,
    bookingUrl,
}: {
    children: ReactNode;
    active: 'home' | 'orders' | 'rent' | 'statements';
    bookingUrl?: string;
}) {
    const { portal, portal_flash: message } = usePage<{
        portal: Portal;
        portal_flash?: string | null;
    }>().props;
    const links: {
        key: typeof active;
        label: string;
        href: string;
        external?: boolean;
    }[] = [
        { key: 'home', label: 'Home', href: PortalController.home.url() },
        { key: 'orders', label: 'Orders', href: PortalController.orders.url() },
        {
            key: 'rent',
            label: 'Rent equipment',
            href: bookingUrl ?? `/book/${portal.yard.slug}`,
            external: true,
        },
        {
            key: 'statements',
            label: 'Statements',
            href: PortalController.statement.url(),
        },
    ];

    return (
        <div className="min-h-svh bg-background text-[15px]">
            <header className="border-b border-border bg-card">
                <div className="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-4 px-4 py-3 sm:px-6">
                    <div className="text-lg font-bold">
                        {portal.yard.name}{' '}
                        <span className="font-normal text-muted-foreground">
                            · Contractor account
                        </span>
                    </div>
                    <nav
                        className="flex flex-wrap items-center gap-1 text-sm"
                        aria-label="Portal"
                    >
                        {links.map((l) =>
                            l.external ? (
                                <a
                                    key={l.key}
                                    href={l.href}
                                    className="rounded-lg px-3 py-2 hover:bg-accent"
                                >
                                    {l.label}
                                </a>
                            ) : (
                                <Link
                                    key={l.key}
                                    href={l.href}
                                    className={cn(
                                        'rounded-lg px-3 py-2 hover:bg-accent',
                                        active === l.key &&
                                            'bg-accent-soft font-semibold text-[#9a3412]',
                                    )}
                                >
                                    {l.label}
                                </Link>
                            ),
                        )}
                    </nav>
                    <div className="flex items-center gap-3 text-sm">
                        <span className="font-semibold">
                            {portal.organization?.name ?? portal.customer.name}
                        </span>
                        <button
                            type="button"
                            onClick={() =>
                                router.post(PortalAuthController.logout.url())
                            }
                            className="text-muted-foreground underline-offset-4 hover:underline"
                        >
                            Sign out
                        </button>
                    </div>
                </div>
            </header>
            <main className="mx-auto flex max-w-[1200px] flex-col gap-5 px-4 py-6 sm:px-6">
                {message && (
                    <div className="rounded-lg bg-success-soft px-4 py-3 text-sm font-medium text-success">
                        {message}
                    </div>
                )}
                {children}
            </main>
        </div>
    );
}
