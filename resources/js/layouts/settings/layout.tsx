import { Link, usePage } from '@inertiajs/react';
import BusinessController from '@/actions/App/Http/Controllers/Settings/BusinessController';
import AuditController from '@/actions/App/Http/Controllers/Settings/AuditController';
import DeliverySettingsController from '@/actions/App/Http/Controllers/Settings/DeliverySettingsController';
import QuickBooksController from '@/actions/App/Http/Controllers/Settings/QuickBooksController';
import RentalSettingsController from '@/actions/App/Http/Controllers/Settings/RentalSettingsController';
import ShopifyController from '@/actions/App/Http/Controllers/Settings/ShopifyController';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
];

const businessNavItems: NavItem[] = [
    {
        title: 'Business',
        href: BusinessController.edit(),
        icon: null,
    },
    {
        title: 'Delivery',
        href: DeliverySettingsController.edit(),
        icon: null,
    },
    {
        title: 'Rentals',
        href: RentalSettingsController.edit(),
        icon: null,
    },
    {
        title: 'Audit log',
        href: AuditController.index(),
        icon: null,
    },
    {
        title: 'Shopify',
        href: ShopifyController.edit(),
        icon: null,
    },
    {
        title: 'QuickBooks',
        href: QuickBooksController.edit(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { auth } = usePage().props;
    const navItems = auth.can.manageBusiness
        ? [...sidebarNavItems, ...businessNavItems]
        : sidebarNavItems;

    return (
        <div className="px-4 py-6">
            <Heading
                title="Settings"
                description="Your profile, sign-in and business settings"
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label="Settings"
                    >
                        {navItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
