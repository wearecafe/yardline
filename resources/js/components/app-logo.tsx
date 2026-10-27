import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { tenant, auth } = usePage().props;
    const subtitle = auth.can.backOffice ? 'Back office' : tenant?.name;

    return (
        <>
            <div className="flex aspect-square size-7 items-center justify-center rounded-md bg-[#1c1f1d] text-[#eeefec] ring-1 ring-white/10">
                <AppLogoIcon className="size-5 fill-current" />
            </div>
            <div className="ml-1 grid flex-1 text-left leading-tight">
                <span className="truncate text-[17px] font-bold text-[#fafaf9]">
                    YardRunner
                </span>
                {subtitle && (
                    <span className="truncate text-xs text-[#a8a29e]">
                        {subtitle}
                    </span>
                )}
            </div>
        </>
    );
}
