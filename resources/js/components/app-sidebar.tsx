import { Link, usePage } from '@inertiajs/react';
import { LifeBuoy, Route } from 'lucide-react';
import OverviewController from '@/actions/App/Http/Controllers/Admin/OverviewController';
import DriverController from '@/actions/App/Http/Controllers/DriverController';
import FulfillmentController from '@/actions/App/Http/Controllers/FulfillmentController';
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
import {
    navSections,
    reachableSections,
    reportsSection,
    teamSection,
} from '@/lib/navigation';
import { dashboard } from '@/routes';

export function AppSidebar() {
    const { auth, time, modules } = usePage().props;
    const team = teamSection(time, auth.can.manageTime);
    const reports = reportsSection(auth.can.viewProfit);
    // Drivers only reach settings pages here; their run is the way back.
    // YardRunner staff get the back office instead of a yard.
    const sections = auth.can.backOffice
        ? adminNavSections
        : // Drivers who only drive see their run.
          auth.can.drive
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
          : reachableSections(
                [
                    // Rentals is a paid add-on.
                    ...navSections.filter(
                        (section) =>
                            section.label !== 'Rental' || modules.rentals,
                    ),
                    ...(auth.routes['driver.today']
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
                        : []),
                    ...(reports ? [reports] : []),
                    ...(team ? [team] : []),
                ],
                auth.routes,
            );

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
                                        : auth.home === 'orders.queue'
                                          ? FulfillmentController.queue()
                                          : auth.home === 'driver.today'
                                            ? DriverController.today()
                                            : auth.home === 'dashboard' ||
                                                !auth.home
                                              ? dashboard()
                                              : (sections
                                                    .flatMap((s) => s.items)
                                                    .find(
                                                        (i) =>
                                                            i.route ===
                                                            auth.home,
                                                    )?.href ?? dashboard())
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
