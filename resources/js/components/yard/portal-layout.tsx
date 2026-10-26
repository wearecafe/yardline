import { Link, router, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    FileText,
    House,
    LogOut,
    Package,
    Wrench,
} from 'lucide-react';
import type { ReactNode } from 'react';
import PortalController from '@/actions/App/Http/Controllers/Portal/PortalController';
import PortalAuthController from '@/actions/App/Http/Controllers/Portal/PortalAuthController';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

type Portal = {
    customer: { id: number; name: string; first_name: string };
    organization: { id: number; name: string; account_number: string } | null;
    yard: { name: string; slug: string };
};

type Active = 'home' | 'orders' | 'rent' | 'statements';

/**
 * The contractor portal's frame. On a phone: the yard's name and the
 * account up top, and the four places to go as a tab bar at the bottom,
 * where a thumb reaches. On a desktop: everything in one header row.
 */
export function PortalLayout({
    children,
    active,
    bookingUrl,
}: {
    children: ReactNode;
    active: Active;
    bookingUrl?: string;
}) {
    const { portal, portal_flash: message } = usePage<{
        portal: Portal;
        portal_flash?: string | null;
    }>().props;
    const account = portal.organization?.name ?? portal.customer.name;
    const links: {
        key: Active;
        label: string;
        short: string;
        href: string;
        icon: typeof House;
    }[] = [
        {
            key: 'home',
            label: 'Home',
            short: 'Home',
            href: PortalController.home.url(),
            icon: House,
        },
        {
            key: 'orders',
            label: 'Orders',
            short: 'Orders',
            href: PortalController.orders.url(),
            icon: Package,
        },
        {
            key: 'rent',
            label: 'Rent equipment',
            short: 'Rent',
            href: bookingUrl ?? `/book/${portal.yard.slug}`,
            icon: Wrench,
        },
        {
            key: 'statements',
            label: 'Statements',
            short: 'Statement',
            href: PortalController.statement.url(),
            icon: FileText,
        },
    ];

    return (
        <div className="min-h-svh bg-background text-[15px]">
            <header className="sticky top-0 z-20 border-b border-border bg-card/95 backdrop-blur">
                <div className="mx-auto flex h-14 max-w-[1200px] items-center gap-4 px-4 sm:px-6">
                    <Link
                        href={PortalController.home.url()}
                        className="min-w-0 flex-1 truncate font-bold sm:flex-none sm:text-lg"
                    >
                        {portal.yard.name}
                    </Link>

                    <nav
                        className="hidden flex-1 items-center gap-1 text-sm md:flex"
                        aria-label="Portal"
                    >
                        {links.map((l) => (
                            <Link
                                key={l.key}
                                href={l.href}
                                aria-current={
                                    active === l.key ? 'page' : undefined
                                }
                                className={cn(
                                    'rounded-lg px-3 py-2 hover:bg-accent',
                                    active === l.key &&
                                        'bg-accent-soft font-semibold text-[#9a3412]',
                                )}
                            >
                                {l.label}
                            </Link>
                        ))}
                    </nav>

                    <DropdownMenu>
                        <DropdownMenuTrigger className="flex max-w-[45%] shrink-0 items-center gap-1 rounded-lg px-2 py-1.5 text-sm font-semibold hover:bg-accent">
                            <span className="truncate">{account}</span>
                            <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-60">
                            <DropdownMenuLabel className="font-normal">
                                <div className="font-semibold">{account}</div>
                                <div className="text-xs text-muted-foreground">
                                    {portal.organization
                                        ? `${portal.customer.name} · contractor account`
                                        : 'Customer account'}
                                </div>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onSelect={() =>
                                    router.post(
                                        PortalAuthController.logout.url(),
                                    )
                                }
                            >
                                <LogOut className="mr-2 size-4" /> Sign out
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <main className="mx-auto flex max-w-[1200px] flex-col gap-5 px-4 pt-5 pb-28 sm:px-6 md:py-6">
                {message && (
                    <div className="rounded-lg bg-success-soft px-4 py-3 text-sm font-medium text-success">
                        {message}
                    </div>
                )}
                {children}
            </main>

            <nav
                className="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
                aria-label="Portal"
            >
                <div className="mx-auto grid max-w-md grid-cols-4">
                    {links.map((l) => {
                        const Icon = l.icon;
                        const on = active === l.key;

                        return (
                            <Link
                                key={l.key}
                                href={l.href}
                                aria-current={on ? 'page' : undefined}
                                className={cn(
                                    'flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium',
                                    on
                                        ? 'text-primary'
                                        : 'text-muted-foreground',
                                )}
                            >
                                <Icon className="size-5" />
                                {l.short}
                            </Link>
                        );
                    })}
                </div>
            </nav>
        </div>
    );
}
