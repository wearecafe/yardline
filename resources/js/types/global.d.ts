import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth & { can: { manageBusiness: boolean } };
            tenant: { id: number; name: string; currency: string } | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
