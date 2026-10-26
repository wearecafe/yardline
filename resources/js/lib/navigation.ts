import {
    Boxes,
    CalendarRange,
    ClipboardList,
    CreditCard,
    FileSignature,
    Contact,
    Factory,
    LayoutGrid,
    MapPin,
    Package,
    Route,
    Tags,
    Truck,
    Wrench,
} from 'lucide-react';
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
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

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
                title: 'Inventory',
                href: InventoryController.index(),
                icon: Boxes,
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
                title: 'Price lists',
                href: PriceListController.index(),
                icon: Tags,
            },
        ],
    },
];
