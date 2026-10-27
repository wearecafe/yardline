import {
    ChartColumn,
    Boxes,
    Clock,
    ClipboardCheck,
    IdCard,
    MonitorSmartphone,
    UsersRound,
    CalendarRange,
    ClipboardList,
    CreditCard,
    FileSignature,
    Contact,
    Factory,
    LayoutGrid,
    MapPin,
    Package,
    PackagePlus,
    Receipt,
    ShoppingCart,
    Undo2,
    Route,
    Tags,
    Truck,
    Wrench,
} from 'lucide-react';
import CounterController from '@/actions/App/Http/Controllers/CounterController';
import PurchasingController from '@/actions/App/Http/Controllers/PurchasingController';
import ReceivablesController from '@/actions/App/Http/Controllers/ReceivablesController';
import ReturnController from '@/actions/App/Http/Controllers/ReturnController';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import InventoryController from '@/actions/App/Http/Controllers/InventoryController';
import LocationController from '@/actions/App/Http/Controllers/LocationController';
import DispatchController from '@/actions/App/Http/Controllers/DispatchController';
import FulfillmentController from '@/actions/App/Http/Controllers/FulfillmentController';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import CalendarController from '@/actions/App/Http/Controllers/Rentals/CalendarController';
import FleetController from '@/actions/App/Http/Controllers/Rentals/FleetController';
import MaintenanceController from '@/actions/App/Http/Controllers/Rentals/MaintenanceController';
import RentalController from '@/actions/App/Http/Controllers/Rentals/RentalController';
import RentalPaymentController from '@/actions/App/Http/Controllers/Rentals/RentalPaymentController';
import PriceListController from '@/actions/App/Http/Controllers/PriceListController';
import ProfitReportController from '@/actions/App/Http/Controllers/ProfitReportController';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import EmployeeController from '@/actions/App/Http/Controllers/Time/EmployeeController';
import KioskController from '@/actions/App/Http/Controllers/Time/KioskController';
import MyTimeController from '@/actions/App/Http/Controllers/Time/MyTimeController';
import TimesheetController from '@/actions/App/Http/Controllers/Time/TimesheetController';
import WhosInController from '@/actions/App/Http/Controllers/Time/WhosInController';
import { dashboard } from '@/routes';
import type { NavItem, SharedTimeStatus } from '@/types';

export type NavSection = { label: string; items: NavItem[] };

/**
 * Sidebar sections, following the yard and rental mockups.
 */
export const navSections: NavSection[] = [
    {
        label: 'Supply yard',
        items: [
            {
                title: 'Today',
                href: dashboard(),
                icon: LayoutGrid,
                route: 'dashboard',
            },
            {
                title: 'Counter',
                href: CounterController.show(),
                icon: ShoppingCart,
                route: 'counter.show',
            },
            {
                title: 'Orders and picking',
                href: FulfillmentController.queue(),
                icon: ClipboardList,
                route: 'orders.queue',
            },
            {
                title: 'Dispatch',
                href: DispatchController.board(),
                icon: Route,
                route: 'dispatch.board',
            },
            {
                title: 'Products',
                href: ProductController.index(),
                icon: Package,
                route: 'products.index',
            },
            {
                title: 'Stock',
                href: InventoryController.index(),
                icon: Boxes,
                route: 'inventory.index',
            },
            {
                title: 'Purchasing',
                href: PurchasingController.index(),
                icon: PackagePlus,
                route: 'purchasing.index',
            },
            {
                title: 'Returns',
                href: ReturnController.index(),
                icon: Undo2,
                route: 'returns.index',
            },
            {
                title: 'Yards',
                href: LocationController.index(),
                icon: MapPin,
                route: 'locations.index',
            },
        ],
    },
    {
        label: 'Rental',
        items: [
            {
                title: 'Fleet',
                href: FleetController.index(),
                icon: Truck,
                route: 'fleet.index',
            },
            {
                title: 'Calendar',
                href: CalendarController.url(),
                icon: CalendarRange,
                route: 'rentals.calendar',
            },
            {
                title: 'Rentals and contracts',
                href: RentalController.index(),
                icon: FileSignature,
                route: 'rentals.index',
            },
            {
                title: 'Maintenance',
                href: MaintenanceController.index(),
                icon: Wrench,
                route: 'maintenance.index',
            },
            {
                title: 'Rental payments',
                href: RentalPaymentController.url(),
                icon: CreditCard,
                route: 'rental-payments.index',
            },
        ],
    },
    {
        label: 'Accounts',
        items: [
            {
                title: 'Organizations',
                href: OrganizationController.index(),
                icon: Factory,
                route: 'organizations.index',
            },
            {
                title: 'Customers',
                href: CustomerController.index(),
                icon: Contact,
                route: 'customers.index',
            },
            {
                title: 'Receivables',
                href: ReceivablesController.index(),
                icon: Receipt,
                route: 'receivables.index',
            },
            {
                title: 'Price lists',
                href: PriceListController.index(),
                icon: Tags,
                route: 'price-lists.index',
            },
        ],
    },
];

/**
 * The sidebar sections with only the screens this person's roles reach.
 * Items without a route are always shown.
 */
export function reachableSections(
    sections: NavSection[],
    routes: Record<string, boolean>,
): NavSection[] {
    return sections
        .map((section) => ({
            ...section,
            items: section.items.filter(
                (item) => !item.route || routes[item.route] !== false,
            ),
        }))
        .filter((section) => section.items.length > 0);
}

/**
 * Reports with costs and margins: the owner, and admins if the owner allows.
 */
export function reportsSection(canViewProfit: boolean): NavSection | null {
    if (!canViewProfit) {
        return null;
    }

    return {
        label: 'Reports',
        items: [
            {
                title: 'Profit and loss',
                href: ProfitReportController.index(),
                icon: ChartColumn,
            },
        ],
    };
}

/**
 * Time and attendance, once the business has it on. Managers see who's
 * in, timesheets and employees; everyone who clocks in sees their own.
 */
export function teamSection(
    time: SharedTimeStatus,
    canManage: boolean,
): NavSection | null {
    if (!time?.enabled) {
        return null;
    }

    const items: NavItem[] = [];

    if (canManage) {
        items.push(
            {
                title: 'Who’s in',
                href: WhosInController.index(),
                icon: UsersRound,
            },
            {
                title: 'Timesheets',
                href: TimesheetController.index(),
                icon: ClipboardCheck,
            },
            {
                title: 'Employees',
                href: EmployeeController.index(),
                icon: IdCard,
            },
        );
    }

    if (time.tracks) {
        items.push({
            title: 'My time',
            href: MyTimeController.show(),
            icon: Clock,
        });
    }

    items.push({
        title: 'Time clock',
        href: KioskController.show(),
        icon: MonitorSmartphone,
        route: 'time.kiosk',
    });

    return { label: 'Team', items };
}
