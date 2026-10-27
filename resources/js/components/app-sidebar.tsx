import { Link, usePage } from '@inertiajs/react';
import { ClipboardList, LifeBuoy, Route } from 'lucide-react';
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
import { navSections, reportsSection, teamSection } from '@/lib/navigation';
import { dashboard } from '@/routes';

export function AppSidebar() {
    const { auth, time } = usePage().props;
    const team = teamSection(time, auth.can.manageTime);
    const reports = reportsSection(auth.can.viewProfit);
    // Drivers only reach settings pages here; their run is the way back.
    // Order pickers only work the order queue.
    const sections = auth.can.pick
        ? [
              {
                  label: 'Yard',
                  items: [
                      {
                          title: 'Orders and picking',
                          href: FulfillmentController.queue(),
                          icon: ClipboardList,
                      },
                  ],
              },
              // Their own time clock, when the business tracks time.
              ...(team ? [team] : []),
          ]
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
          : [
                ...navSections,
                ...(reports ? [reports] : []),
                ...(team ? [team] : []),
            ];

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
                                    auth.can.pick
                                        ? FulfillmentController.queue()
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
