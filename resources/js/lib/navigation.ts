import {
    Boxes,
    ClipboardList,
    Contact,
    Factory,
    LayoutGrid,
    MapPin,
    Package,
    Tags,
} from 'lucide-react';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import InventoryController from '@/actions/App/Http/Controllers/InventoryController';
import LocationController from '@/actions/App/Http/Controllers/LocationController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
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
                href: OrderController.index(),
                icon: ClipboardList,
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
