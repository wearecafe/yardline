import { Link, router, usePage } from '@inertiajs/react';
import { Clock, LogOut, MapPin, MapPinOff, Settings } from 'lucide-react';
import type { ReactNode } from 'react';
import DriverController from '@/actions/App/Http/Controllers/DriverController';
import MyTimeController from '@/actions/App/Http/Controllers/Time/MyTimeController';
import AppLogoIcon from '@/components/app-logo-icon';
import { useLocationSharing } from '@/hooks/use-location-sharing';
import type { LocationSharing } from '@/hooks/use-location-sharing';
import { cn } from '@/lib/utils';
import { dashboard, logout } from '@/routes';
import { edit } from '@/routes/profile';

function SharingBadge({ sharing }: { sharing: LocationSharing }) {
    if (sharing.state === 'off') {
        return null;
    }

    const on = sharing.state === 'sharing';
    const label = {
        sharing: 'Sharing location',
        asking: 'Finding you…',
        denied: 'Location off',
        unsupported: 'No location',
    }[sharing.state];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold',
                on
                    ? 'bg-success-soft text-success'
                    : 'bg-secondary text-subtle',
                sharing.state === 'denied' && 'bg-warning-soft text-warning',
            )}
            title={
                sharing.state === 'denied'
                    ? 'Allow location for this site in your browser settings so dispatch can see where the truck is.'
                    : 'Dispatch sees where the truck is while this page is open on your phone.'
            }
        >
            {on ? (
                <MapPin className="size-3" />
            ) : (
                <MapPinOff className="size-3" />
            )}
            {label}
        </span>
    );
}

/**
 * On the clock or not, one tap from the clock itself.
 */
function ClockChip() {
    const { time } = usePage().props;

    if (!time?.tracks) {
        return null;
    }

    const label = {
        working: 'On the clock',
        on_break: 'On break',
        out: 'Clock in',
    }[time.state ?? 'out'];

    return (
        <Link
            href={MyTimeController.show()}
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold',
                time.state === 'working'
                    ? 'bg-success-soft text-success'
                    : time.state === 'on_break'
                      ? 'bg-warning-soft text-warning'
                      : 'bg-primary text-primary-foreground',
            )}
        >
            <Clock className="size-3" />
            {label}
        </Link>
    );
}

/**
 * The driver's phone: a slim header, the run below. Persists between the
 * run and its stops so location sharing keeps going.
 */
export default function DriverLayout({ children }: { children: ReactNode }) {
    const { auth, tenant, shareLocation, isDemo } = usePage().props;
    const live = useLocationSharing(shareLocation);
    // In the demo yard the truck's position is simulated by the server.
    const sharing: LocationSharing =
        isDemo && auth.can.drive
            ? { state: 'sharing', lastSentAt: null, accuracy: null }
            : live;

    return (
        <div className="min-h-svh bg-background">
            <header className="sticky top-0 z-10 border-b border-sidebar-border bg-sidebar text-[#fafaf9]">
                <div className="mx-auto flex max-w-lg items-center gap-3 px-4 py-3">
                    <Link
                        href={
                            auth.can.drive
                                ? DriverController.today.url()
                                : dashboard().url
                        }
                        className="flex size-8 shrink-0 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                        aria-label={
                            auth.can.drive ? 'My run' : 'Back to the yard'
                        }
                    >
                        <AppLogoIcon className="size-4 fill-current" />
                    </Link>
                    <div className="min-w-0 flex-1 leading-tight">
                        <div className="truncate text-sm font-semibold">
                            {auth.user.name}
                        </div>
                        <div className="truncate text-xs text-[#a8a29e]">
                            {tenant?.name}
                        </div>
                    </div>
                    <ClockChip />
                    <SharingBadge sharing={sharing} />
                    <Link
                        href={edit()}
                        className="rounded-md p-2 text-[#a8a29e] hover:text-[#fafaf9]"
                        aria-label="Settings"
                    >
                        <Settings className="size-5" />
                    </Link>
                    <Link
                        href={logout()}
                        as="button"
                        onClick={() => router.flushAll()}
                        className="rounded-md p-2 text-[#a8a29e] hover:text-[#fafaf9]"
                        aria-label="Sign out"
                    >
                        <LogOut className="size-5" />
                    </Link>
                </div>
            </header>
            <main className="mx-auto max-w-lg px-4 py-4 pb-16">{children}</main>
        </div>
    );
}
