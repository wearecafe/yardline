import { Link, usePage } from '@inertiajs/react';
import AlertsController from '@/actions/App/Http/Controllers/Settings/AlertsController';
import BillingController from '@/actions/App/Http/Controllers/Settings/BillingController';
import BrandingController from '@/actions/App/Http/Controllers/Settings/BrandingController';
import BusinessController from '@/actions/App/Http/Controllers/Settings/BusinessController';
import AuditController from '@/actions/App/Http/Controllers/Settings/AuditController';
import DeliverySettingsController from '@/actions/App/Http/Controllers/Settings/DeliverySettingsController';
import DriverAccountsController from '@/actions/App/Http/Controllers/Settings/DriverAccountsController';
import OrderPickingController from '@/actions/App/Http/Controllers/Settings/OrderPickingController';
import PaymentsController from '@/actions/App/Http/Controllers/Settings/PaymentsController';
import QuickBooksController from '@/actions/App/Http/Controllers/Settings/QuickBooksController';
import RoleSettingsController from '@/actions/App/Http/Controllers/Settings/RoleSettingsController';
import RentalSettingsController from '@/actions/App/Http/Controllers/Settings/RentalSettingsController';
import SendingDomainController from '@/actions/App/Http/Controllers/Settings/SendingDomainController';
import TimeSettingsController from '@/actions/App/Http/Controllers/Settings/TimeSettingsController';
import UserAccountsController from '@/actions/App/Http/Controllers/Settings/UserAccountsController';
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
    {
        title: 'Alerts',
        href: AlertsController.edit(),
        icon: null,
    },
];

const usersNavItems: NavItem[] = [
    {
        title: 'Users',
        href: UserAccountsController.index(),
        icon: null,
    },
    {
        title: 'Roles',
        href: RoleSettingsController.edit(),
        icon: null,
    },
];

const quickBooksNavItem: NavItem = {
    title: 'QuickBooks',
    href: QuickBooksController.edit(),
    icon: null,
};

const businessNavItems: NavItem[] = [
    {
        title: 'Business',
        href: BusinessController.edit(),
        icon: null,
    },
    {
        title: 'Branding',
        href: BrandingController.edit(),
        icon: null,
    },
    {
        title: 'Sending domain',
        href: SendingDomainController.edit(),
        icon: null,
    },
    {
        title: 'Delivery',
        href: DeliverySettingsController.edit(),
        icon: null,
    },
    {
        title: 'Order picking',
        href: OrderPickingController.edit(),
        icon: null,
    },
    {
        title: 'Drivers',
        href: DriverAccountsController.index(),
        icon: null,
    },
    {
        title: 'Rentals',
        href: RentalSettingsController.edit(),
        icon: null,
    },
    {
        title: 'Time',
        href: TimeSettingsController.edit(),
        icon: null,
    },
    {
        title: 'Payments',
        href: PaymentsController.edit(),
        icon: null,
    },
    {
        title: 'Billing',
        href: BillingController.edit(),
        icon: null,
    },
    {
        title: 'Audit log',
        href: AuditController.index(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { auth, modules } = usePage().props;
    // Rental settings only once the Rentals add-on is on.
    const business = businessNavItems.filter(
        (item) => item.title !== 'Rentals' || modules.rentals,
    );
    const navItems = [
        ...sidebarNavItems,
        ...(auth.can.manageBusiness ? business.slice(0, 1) : []),
        ...(auth.can.manageUsers ? usersNavItems : []),
        ...(auth.can.manageBusiness ? business.slice(1) : []),
        // Accounts sees QuickBooks sync errors too.
        ...(auth.can.quickbooksLog ? [quickBooksNavItem] : []),
    ];

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
