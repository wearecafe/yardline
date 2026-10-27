import type { Auth } from '@/types/auth';
import type { SharedBilling } from '@/types/billing';
import type { SharedTimeStatus } from '@/types/time';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth & {
                roles: { value: string; label: string }[];
                can: {
                    manageBusiness: boolean;
                    manageUsers: boolean;
                    backOffice: boolean;
                    drive: boolean;
                    pick: boolean;
                    workQueue: boolean;
                    seePrices: boolean;
                    seeCosts: boolean;
                    setPrices: boolean;
                    priceExempt: boolean;
                    manageTime: boolean;
                    quickbooksLog: boolean;
                    viewProfit: boolean;
                };
                /** Sidebar route names, and whether this person reaches each. */
                routes: Record<string, boolean>;
                /** The route name this person starts on. */
                home: string | null;
            };
            tenant: { id: number; name: string; currency: string } | null;
            isDemo: boolean;
            shareLocation: boolean;
            time: SharedTimeStatus;
            modules: { rentals: boolean };
            billing: SharedBilling | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
