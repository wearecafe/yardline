import { Link, usePage } from '@inertiajs/react';
import { LifeBuoy, Route } from 'lucide-react';
import OverviewController from '@/actions/App/Http/Controllers/Admin/OverviewController';
import DriverController from '@/actions/App/Http/Controllers/DriverController';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
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
import { adminNavSections } from '@/lib/admin-navigation';
import { navSections } from '@/lib/navigation';
import { dashboard } from '@/routes';

export function AppSidebar() {
    const { auth } = usePage().props;
    // Drivers only reach settings pages here; their run is the way back.
    // Yardline staff get the back office instead of a yard.
    const sections = auth.can.backOffice
        ? adminNavSections
        : auth.can.drive
          ? [
                {
                    label: 'Driving',
                    items: [
                        {
                            title: 'My run',
                            href: DriverController.today(),
                            icon: Route,
                        },
                    ],
                },
            ]
          : navSections;

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            className="hover:bg-transparent"
                        >
                            <Link
                                href={
                                    auth.can.backOffice
                                        ? OverviewController.url()
                                        : auth.can.drive
                                          ? DriverController.today()
                                          : dashboard()
                                }
                                prefetch
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-2">
                {sections.map((section) => (
                    <NavMain
                        key={section.label}
                        label={section.label}
                        items={section.items}
                    />
                ))}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter
                    items={[{ title: 'Help', href: '/help', icon: LifeBuoy }]}
                />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
