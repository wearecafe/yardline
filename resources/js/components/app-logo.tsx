import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { tenant } = usePage().props;

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon className="size-5 fill-current" />
            </div>
            <div className="ml-1 grid flex-1 text-left leading-tight">
                <span className="truncate font-display text-base font-bold tracking-tight">
                    Yardline
                </span>
                {tenant && (
                    <span className="truncate text-xs text-muted-foreground">
                        {tenant.name}
                    </span>
                )}
            </div>
        </>
    );
}
