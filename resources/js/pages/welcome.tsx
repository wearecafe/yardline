import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login, register } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Yardline" />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-5">
                    <div className="flex items-center gap-2">
                        <AppLogoIcon className="size-7 fill-current" />
                        <span className="font-display text-xl font-bold tracking-tight">
                            Yardline
                        </span>
                    </div>
                    <nav className="flex items-center gap-2 text-sm">
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground"
                            >
                                Open Yardline
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="rounded-md px-4 py-2 hover:bg-accent"
                                >
                                    Log in
                                </Link>
                                <Link
                                    href={register()}
                                    className="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground"
                                >
                                    Set up your yard
                                </Link>
                            </>
                        )}
                    </nav>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-6 pb-24">
                    <h1 className="max-w-3xl font-display text-5xl leading-[1.05] font-bold tracking-tight md:text-7xl">
                        Every pallet, tonne and yard of it, accounted for.
                    </h1>
                    <div
                        className="tape-rule mt-8 max-w-3xl"
                        aria-hidden="true"
                    />
                    <p className="mt-6 max-w-xl text-lg text-muted-foreground">
                        Counter orders, contractor pricing, credit limits,
                        rentals and stock for masonry and landscape supply
                        yards.
                    </p>
                </main>
            </div>
        </>
    );
}
