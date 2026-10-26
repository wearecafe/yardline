import { Link } from '@inertiajs/react';
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
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const counterItems: NavItem[] = [
    { title: 'Today', href: dashboard(), icon: LayoutGrid },
    { title: 'Orders', href: OrderController.index(), icon: ClipboardList },
    {
        title: 'Organizations',
        href: OrganizationController.index(),
        icon: Factory,
    },
    { title: 'Customers', href: CustomerController.index(), icon: Contact },
];

const yardItems: NavItem[] = [
    { title: 'Products', href: ProductController.index(), icon: Package },
    { title: 'Price lists', href: PriceListController.index(), icon: Tags },
    { title: 'Inventory', href: InventoryController.index(), icon: Boxes },
    { title: 'Yards', href: LocationController.index(), icon: MapPin },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain label="Counter" items={counterItems} />
                <NavMain label="Catalog and stock" items={yardItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
