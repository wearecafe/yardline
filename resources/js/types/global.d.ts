import type { Auth } from '@/types/auth';
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
                can: {
                    manageBusiness: boolean;
                    manageUsers: boolean;
                    backOffice: boolean;
                    drive: boolean;
                    pick: boolean;
                    priceExempt: boolean;
                    manageTime: boolean;
                };
            };
            tenant: { id: number; name: string; currency: string } | null;
            isDemo: boolean;
            shareLocation: boolean;
            time: SharedTimeStatus;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
