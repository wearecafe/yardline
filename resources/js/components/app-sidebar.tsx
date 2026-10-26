import { Link } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
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
import { navSections } from '@/lib/navigation';
import { dashboard } from '@/routes';

export function AppSidebar() {
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
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-2">
                {navSections.map((section) => (
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
