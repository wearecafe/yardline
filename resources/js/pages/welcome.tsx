import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { dashboard, login, register } from '@/routes';

type Props = {
    demo: boolean;
    canRegister: boolean;
};

type Module = {
    label: string;
    title: string;
    lead: string;
    points: string[];
};

const modules: Module[] = [
    {
        label: 'Counter',
        title: 'Sell it the way the yard sells it',
        lead: 'By the bag, the pallet, the tonne or the yard. Stock is counted in one base unit and every sale converts back to it.',
        points: [
            'Selling units with their own prices, increments and deposits',
            'Contract prices, then price lists with quantity breaks and dates, then retail',
            'Quotes that hold their price until they expire, accepted from a link',
            'Credit limits and holds checked before an order goes on account',
            'Counter screen for walk-ins: ring up, take payment, hand over',
        ],
    },
    {
        label: 'Yard & delivery',
        title: 'From pick ticket to proof of delivery',
        lead: 'Orders land in the queue, get picked on the yard kiosk, loaded on a truck and signed for at the job site.',
        points: [
            'Pick queue and a kiosk built for gloves, with bin locations',
            'Dispatch board: trucks down the side, hours across',
            'Delivery fees by zone, distance or truckload',
            'ETA texts to customers in one tap',
            'Signature and photo proof of delivery; partial shipments tracked',
        ],
    },
    {
        label: 'Rentals',
        title: 'Equipment rental in the same system',
        lead: 'Skid steers, plate compactors and saws booked, signed for, sent out and checked back in, next to the material orders.',
        points: [
            'Availability calendar that won’t double-book a unit',
            'The cheapest mix of hourly, daily, weekly and monthly rates',
            'Contracts signed on a tablet or from a texted link',
            'Hour meters and service intervals that lock a unit out when due',
            'Online booking page for homeowners',
        ],
    },
    {
        label: 'Inventory',
        title: 'Stock you can trust at every yard',
        lead: 'A ledger of every receipt, sale, transfer and count. Colour runs kept apart so a patio ships from one run.',
        points: [
            'Purchase orders sent to suppliers and received into runs',
            'Transfers between yards',
            'Cycle counts that post only the difference',
            'Average cost updated on every receipt; margin flagged when it moves',
        ],
    },
    {
        label: 'Receivables',
        title: 'Get paid for what went out the gate',
        lead: 'Invoices by terms, aging by account, and the returns and pallet deposits that make yard billing messy.',
        points: [
            'Invoices issued on confirmation, due by the account’s terms',
            'Aging buckets and statements to print, download or email',
            'Returns restocked less a fee, credited to the account',
            'Pallet deposits charged going out and credited coming back',
            'Card payments and payment links through Stripe, tax by Stripe Tax',
        ],
    },
    {
        label: 'Contractor portal',
        title: 'Let contractors help themselves',
        lead: 'Your account customers sign in with an emailed link. No passwords, nothing to install.',
        points: [
            'Reorder last week’s job in one tap',
            'Patio calculator that turns square feet into pavers, base and sand',
            'Live delivery status and ETA',
            'Statement and open invoices',
            'Request a pallet pickup; it lands on the dispatch board',
        ],
    },
];

const queue: {
    number: string;
    who: string;
    what: string;
    tone: ChipTone;
    status: string;
}[] = [
    {
        number: 'SO-1042',
        who: 'Brantwood Landscaping',
        what: '1,200 sq ft charcoal paver · 3 t screenings',
        tone: 'info',
        status: 'Picking',
    },
    {
        number: 'SO-1043',
        who: 'Halton Masonry',
        what: '80 bags Type S · 2 pallets brick',
        tone: 'success',
        status: 'Loaded',
    },
    {
        number: 'RN-0311',
        who: 'Dana Kowalski',
        what: 'Plate compactor · 2 days',
        tone: 'warning',
        status: 'Due back 4 pm',
    },
    {
        number: 'SO-1044',
        who: 'Kerncliff Homes',
        what: '14 yd triple mix · bulk bags',
        tone: 'neutral',
        status: 'Unscheduled',
    },
];

export default function Welcome({ demo, canRegister }: Props) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Yardline: inventory and orders for masonry and landscape supply yards" />
            <div className="min-h-screen bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-5 sm:px-6">
                    <Link href="/" className="flex items-center gap-2">
                        <AppLogoIcon className="size-7 fill-current" />
                        <span className="text-xl font-bold tracking-tight">
                            Yardline
                        </span>
                    </Link>
                    <nav className="flex items-center gap-1 text-sm sm:gap-2">
                        <a
                            href="#features"
                            className="hidden rounded-md px-3 py-2 hover:bg-secondary sm:inline"
                        >
                            Features
                        </a>
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="rounded-md bg-primary px-4 py-2.5 font-medium text-primary-foreground"
                            >
                                Open Yardline
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="rounded-md px-3 py-2.5 hover:bg-secondary"
                                >
                                    Log in
                                </Link>
                                {demo ? (
                                    <Link
                                        href="/demo"
                                        className="rounded-md bg-primary px-4 py-2.5 font-medium text-primary-foreground"
                                    >
                                        Try the demo
                                    </Link>
                                ) : (
                                    canRegister && (
                                        <Link
                                            href={register()}
                                            className="rounded-md bg-primary px-4 py-2.5 font-medium text-primary-foreground"
                                        >
                                            Set up your yard
                                        </Link>
                                    )
                                )}
                            </>
                        )}
                    </nav>
                </header>

                <main>
                    <section className="mx-auto grid w-full max-w-6xl gap-12 px-4 pt-12 pb-20 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:items-center lg:pt-20">
                        <div>
                            <p className="font-mono text-xs font-semibold tracking-widest text-primary uppercase">
                                For masonry and landscape supply yards
                            </p>
                            <h1 className="mt-4 text-4xl leading-[1.05] font-bold tracking-tight sm:text-6xl">
                                Every pallet, tonne and yard of it, accounted
                                for.
                            </h1>
                            <div
                                className="tape-rule mt-8 max-w-xl"
                                aria-hidden="true"
                            />
                            <p className="mt-6 max-w-xl text-lg text-muted-foreground">
                                Counter orders, contractor pricing, deliveries,
                                equipment rentals, stock and receivables in one
                                system built around how a yard actually works.
                            </p>
                            <div className="mt-8 flex flex-wrap gap-3">
                                {demo ? (
                                    <Link
                                        href="/demo"
                                        className="inline-flex h-11 items-center rounded-md bg-primary px-5 font-medium text-primary-foreground"
                                    >
                                        Try the demo yard
                                    </Link>
                                ) : (
                                    canRegister &&
                                    !auth.user && (
                                        <Link
                                            href={register()}
                                            className="inline-flex h-11 items-center rounded-md bg-primary px-5 font-medium text-primary-foreground"
                                        >
                                            Set up your yard
                                        </Link>
                                    )
                                )}
                                <a
                                    href="#features"
                                    className="inline-flex h-11 items-center rounded-md border border-border bg-card px-5 font-medium hover:border-foreground"
                                >
                                    See what’s in it
                                </a>
                            </div>
                            {demo && (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    No sign-up. Pick a role (owner, office,
                                    counter, yard lead) and click around a
                                    seeded yard.
                                </p>
                            )}
                        </div>

                        <QueuePreview />
                    </section>

                    <section className="border-y border-border bg-card">
                        <dl className="mx-auto grid w-full max-w-6xl grid-cols-2 gap-px px-4 sm:px-6 md:grid-cols-4">
                            <Stat
                                figure="1"
                                label="system for material and rentals"
                            />
                            <Stat
                                figure="5%"
                                label="pick tolerance before it flags"
                            />
                            <Stat figure="3" label="ways to price delivery" />
                            <Stat
                                figure="0"
                                label="passwords for contractors"
                            />
                        </dl>
                    </section>

                    <section
                        id="features"
                        className="mx-auto w-full max-w-6xl scroll-mt-8 px-4 py-20 sm:px-6"
                    >
                        <h2 className="max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                            Built for the counter, the yard and the office.
                        </h2>
                        <p className="mt-4 max-w-2xl text-muted-foreground">
                            Generic inventory software counts boxes. A supply
                            yard sells bulk by the tonne, pavers by the square
                            foot, rents the compactor to lay them, and charges
                            for the pallet they came on.
                        </p>

                        <div className="mt-12 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            {modules.map((module) => (
                                <article
                                    key={module.label}
                                    className="flex flex-col rounded-lg border border-border bg-card p-6"
                                >
                                    <p className="font-mono text-xs font-semibold tracking-widest text-primary uppercase">
                                        {module.label}
                                    </p>
                                    <h3 className="mt-2 text-lg font-semibold">
                                        {module.title}
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {module.lead}
                                    </p>
                                    <ul className="mt-4 grid gap-2 text-sm">
                                        {module.points.map((point) => (
                                            <li
                                                key={point}
                                                className="flex gap-2"
                                            >
                                                <span
                                                    className="mt-2 size-1.5 shrink-0 rounded-full bg-primary"
                                                    aria-hidden="true"
                                                />
                                                <span>{point}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </article>
                            ))}
                        </div>
                    </section>

                    <section className="bg-foreground text-background">
                        <div className="mx-auto grid w-full max-w-6xl gap-10 px-4 py-20 sm:px-6 md:grid-cols-3">
                            <Point title="Every yard, one book">
                                Each yard keeps its own stock, bins and trucks.
                                Accounts, prices and credit are shared across
                                them.
                            </Point>
                            <Point title="A record of who changed what">
                                Price changes, credit limits and order
                                confirmations are logged with the person and the
                                before and after.
                            </Point>
                            <Point title="Roles that match the yard">
                                Owners set prices and credit. The office runs
                                accounts. The counter sells. The yard picks,
                                loads and delivers.
                            </Point>
                        </div>
                    </section>

                    <section className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
                        <h2 className="text-3xl font-bold tracking-tight">
                            Works with what you already use
                        </h2>
                        <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Integration
                                name="Shopify"
                                status="Available"
                                tone="success"
                            >
                                Products, every selling unit as a variant, and
                                stock pushed to your storefront.
                            </Integration>
                            <Integration
                                name="Stripe"
                                status="Available"
                                tone="success"
                            >
                                Card payments at the counter and payment links
                                by text or email.
                            </Integration>
                            <Integration
                                name="Stripe Tax"
                                status="Available"
                                tone="success"
                            >
                                Sales tax worked out per order, with exemptions
                                per account.
                            </Integration>
                            <Integration
                                name="QuickBooks Online"
                                status="Coming soon"
                                tone="neutral"
                            >
                                Invoices, payments and rentals posted to your
                                books.
                            </Integration>
                        </div>
                    </section>

                    <section className="mx-auto w-full max-w-6xl px-4 pb-24 sm:px-6">
                        <div className="rounded-lg border border-border bg-card p-8 sm:p-12">
                            <h2 className="max-w-xl text-3xl font-bold tracking-tight">
                                {demo
                                    ? 'Walk the demo yard.'
                                    : 'Ready to count it all?'}
                            </h2>
                            <p className="mt-3 max-w-xl text-muted-foreground">
                                {demo
                                    ? 'Escarpment Stone & Landscape Supply has two yards, a truck fleet, a rental fleet and a season of orders. Sign in as anyone on the team, or as a contractor in the portal.'
                                    : 'Set up your yards, catalogue and accounts, and take your first order today.'}
                            </p>
                            <div className="mt-6 flex flex-wrap gap-3">
                                {demo ? (
                                    <Link
                                        href="/demo"
                                        className="inline-flex h-11 items-center rounded-md bg-primary px-5 font-medium text-primary-foreground"
                                    >
                                        Try the demo yard
                                    </Link>
                                ) : (
                                    canRegister && (
                                        <Link
                                            href={register()}
                                            className="inline-flex h-11 items-center rounded-md bg-primary px-5 font-medium text-primary-foreground"
                                        >
                                            Set up your yard
                                        </Link>
                                    )
                                )}
                                {!auth.user && (
                                    <Link
                                        href={login()}
                                        className="inline-flex h-11 items-center rounded-md border border-border px-5 font-medium hover:border-foreground"
                                    >
                                        Log in
                                    </Link>
                                )}
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-border">
                    <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-6 text-sm text-muted-foreground sm:px-6">
                        <span>Yardline</span>
                        <span>
                            Inventory, orders and rentals for supply yards.
                        </span>
                    </div>
                </footer>
            </div>
        </>
    );
}

function QueuePreview() {
    return (
        <div
            className="rounded-lg border border-border bg-card shadow-sm"
            aria-label="Example of the yard queue"
        >
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <span className="text-sm font-semibold">
                    Today · Waterdown yard
                </span>
                <span className="font-mono text-xs text-muted-foreground">
                    4 open
                </span>
            </div>
            <ul className="divide-y divide-border">
                {queue.map((row) => (
                    <li key={row.number} className="grid gap-1 px-4 py-3">
                        <div className="flex items-center justify-between gap-3">
                            <span className="truncate text-sm font-medium">
                                <span className="font-mono text-muted-foreground">
                                    {row.number}
                                </span>{' '}
                                {row.who}
                            </span>
                            <Chip tone={row.tone}>{row.status}</Chip>
                        </div>
                        <span className="truncate text-sm text-muted-foreground">
                            {row.what}
                        </span>
                    </li>
                ))}
            </ul>
            <div className="grid grid-cols-3 border-t border-border text-center">
                <Mini figure="$18,420" label="Sold today" />
                <Mini figure="6" label="Deliveries" />
                <Mini figure="3" label="Units out" />
            </div>
        </div>
    );
}

function Mini({ figure, label }: { figure: string; label: string }) {
    return (
        <div className="px-2 py-3">
            <div className="font-mono text-base font-semibold">{figure}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}

function Stat({ figure, label }: { figure: string; label: string }) {
    return (
        <div className="py-8 pr-4">
            <dt className="sr-only">{label}</dt>
            <dd className="font-mono text-3xl font-semibold text-primary">
                {figure}
            </dd>
            <dd className="mt-1 text-sm text-muted-foreground">{label}</dd>
        </div>
    );
}

function Point({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <h3 className="text-lg font-semibold">{title}</h3>
            <p className="mt-2 text-sm opacity-75">{children}</p>
        </div>
    );
}

function Integration({
    name,
    status,
    tone,
    children,
}: {
    name: string;
    status: string;
    tone: ChipTone;
    children: ReactNode;
}) {
    return (
        <div className="rounded-lg border border-border bg-card p-5">
            <div className="flex items-center justify-between gap-2">
                <h3 className="font-semibold">{name}</h3>
                <Chip tone={tone}>{status}</Chip>
            </div>
            <p className="mt-2 text-sm text-muted-foreground">{children}</p>
        </div>
    );
}
