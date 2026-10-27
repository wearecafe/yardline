import { usePage } from '@inertiajs/react';
import { AlertsPrompt } from '@/components/alerts-prompt';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { BillingBanner } from '@/components/billing-banner';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const { isDemo } = usePage().props;

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                {isDemo && (
                    <p className="border-b border-warning/20 bg-warning-soft px-4 py-1.5 text-center text-sm">
                        You’re in the demo yard. Try anything: it’s rebuilt
                        every night.
                    </p>
                )}
                <BillingBanner />
                <AlertsPrompt className="mx-4 mt-4" />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
