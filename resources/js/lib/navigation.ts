import {
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
            { title: 'Today', href: dashboard(), icon: LayoutGrid },
            {
                title: 'Counter',
                href: CounterController.show(),
                icon: ShoppingCart,
            },
            {
                title: 'Orders and picking',
                href: FulfillmentController.queue(),
                icon: ClipboardList,
            },
            {
                title: 'Dispatch',
                href: DispatchController.board(),
                icon: Route,
            },
            {
                title: 'Products',
                href: ProductController.index(),
                icon: Package,
            },
            {
                title: 'Stock',
                href: InventoryController.index(),
                icon: Boxes,
            },
            {
                title: 'Purchasing',
                href: PurchasingController.index(),
                icon: PackagePlus,
            },
            {
                title: 'Returns',
                href: ReturnController.index(),
                icon: Undo2,
            },
            { title: 'Yards', href: LocationController.index(), icon: MapPin },
        ],
    },
    {
        label: 'Rental',
        items: [
            { title: 'Fleet', href: FleetController.index(), icon: Truck },
            {
                title: 'Calendar',
                href: CalendarController.url(),
                icon: CalendarRange,
            },
            {
                title: 'Rentals and contracts',
                href: RentalController.index(),
                icon: FileSignature,
            },
            {
                title: 'Maintenance',
                href: MaintenanceController.index(),
                icon: Wrench,
            },
            {
                title: 'Rental payments',
                href: RentalPaymentController.url(),
                icon: CreditCard,
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
            },
            {
                title: 'Customers',
                href: CustomerController.index(),
                icon: Contact,
            },
            {
                title: 'Receivables',
                href: ReceivablesController.index(),
                icon: Receipt,
            },
            {
                title: 'Price lists',
                href: PriceListController.index(),
                icon: Tags,
            },
        ],
    },
];

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
    });

    return { label: 'Team', items };
}
