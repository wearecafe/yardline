import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { tenant } = usePage().props;

    return (
        <>
            <div className="flex aspect-square size-7 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon className="size-4 fill-current" />
            </div>
            <div className="ml-1 grid flex-1 text-left leading-tight">
                <span className="truncate text-[17px] font-bold text-[#fafaf9]">
                    Yardline
                </span>
                {tenant && (
                    <span className="truncate text-xs text-[#a8a29e]">
                        {tenant.name}
                    </span>
                )}
            </div>
        </>
    );
}
