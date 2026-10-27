import { createInertiaApp } from '@inertiajs/react';
import { OfflineBanner } from '@/components/offline-banner';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import DriverLayout from '@/layouts/driver-layout';
import SettingsLayout from '@/layouts/settings/layout';
import SetupLayout from '@/layouts/setup-layout';
import { initializePwa } from '@/lib/pwa';

const appName = import.meta.env.VITE_APP_NAME || 'YardRunner';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name.startsWith('help/'):
            case name.startsWith('kiosk/'):
            case name.startsWith('public/'):
            case name.startsWith('print/'):
            case name.startsWith('portal/'):
                return null;
            case name.startsWith('driver/'):
                return DriverLayout;
            case name.startsWith('auth/'):
            case name.startsWith('billing/'):
                return AuthLayout;
            case name.startsWith('setup/'):
                return SetupLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
                <OfflineBanner />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#C2410C',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// The installable app: service worker, updates, install prompt.
initializePwa();
