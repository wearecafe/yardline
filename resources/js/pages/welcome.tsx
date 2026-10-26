import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import {
    AgingMock,
    DispatchMock,
    PortalMock,
    PricingMock,
    QueueMock,
    RentalMock,
    StockMock,
} from '@/components/marketing/mocks';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { dashboard, login, register } from '@/routes';

type Props = {
    demo: boolean;
    canRegister: boolean;
};

const nav = [
    { href: '#day', label: 'A day in the yard' },
    { href: '#features', label: 'Features' },
    { href: '#rentals', label: 'Rentals' },
    { href: '#portal', label: 'Contractor portal' },
    { href: '#faq', label: 'FAQ' },
    { href: '/help', label: 'Help' },
];

const pains = [
    {
        title: 'The whiteboard is the dispatch system',
        body: 'Deliveries live on a board by the door and in the yard lead’s head. Customers call to ask where their load is.',
    },
    {
        title: 'Prices live in three places',
        body: 'Contractor pricing in a binder, a builder’s job price in an email, retail in the POS. The counter guesses which one applies.',
    },
    {
        title: 'Rentals are a separate world',
        body: 'The compactor that goes out with the pavers is booked in another app, or on paper, and never shows on the account.',
    },
    {
        title: 'Pallets walk away',
        body: 'Deposits are charged sometimes, credited when someone remembers, and nobody knows how many are sitting on job sites.',
    },
];

const day: { time: string; who: string; title: string; body: string }[] = [
    {
        time: '6:45',
        who: 'Contractor',
        title: 'Mike reorders from the job site',
        body: 'He opens the portal on his phone, taps reorder on last week’s patio job and adds two pallets. It lands as a draft for the counter.',
    },
    {
        time: '7:10',
        who: 'Counter',
        title: 'Ryan confirms it on account',
        body: 'Brantwood’s contract price applies, the credit check passes, tax is calculated and the invoice is issued on net 30 terms.',
    },
    {
        time: '7:30',
        who: 'Yard',
        title: 'Owen picks it on the kiosk',
        body: 'The pick ticket lists bins and the colour run to ship from. Short by a pallet? The kiosk records what actually went on.',
    },
    {
        time: '8:15',
        who: 'Dispatch',
        title: 'Loaded on Boom 1, ETA texted',
        body: 'Dragged onto the board at 9 o’clock. Mike gets a text with a two-hour window.',
    },
    {
        time: '10:40',
        who: 'Driver',
        title: 'Signed for at the curb',
        body: 'Signature and photo on the driver’s phone. Pallet deposits go on the account automatically.',
    },
    {
        time: '3:50',
        who: 'Yard',
        title: 'The compactor comes back',
        body: 'Hours read off the meter, inspection done, deposit settled. It’s 14 hours from service, so it gets flagged.',
    },
    {
        time: '4:30',
        who: 'Office',
        title: 'Lisa runs statements',
        body: 'Aging by account, statements emailed, and Halton’s pallet pickup scheduled for tomorrow.',
    },
];

const everything: { group: string; items: string[] }[] = [
    {
        group: 'Selling',
        items: [
            'Selling units with conversion factors',
            'Fractional and bulk quantities',
            'Contract prices by organization',
            'Price lists with dates and quantity breaks',
            'Quotes with expiry and online accept',
            'Walk-in counter checkout',
            'Credit limits, holds and overrides',
            'Prepaid or on-account settlement',
        ],
    },
    {
        group: 'Accounts',
        items: [
            'Organizations with many contacts',
            'Contact roles and charge rights',
            'Job sites with delivery notes',
            'PO numbers required per site',
            'Tax exemptions per account',
            'Payment terms per account',
            'Customer documents on file',
            'Contractor portal access',
        ],
    },
    {
        group: 'Yard & delivery',
        items: [
            'Pick queue and yard kiosk',
            'Bin locations',
            'Partial shipments',
            'Trucks with capacity and boom',
            'Dispatch board by hour',
            'Zone, distance or truckload fees',
            'ETA text messages',
            'Signature and photo proof',
        ],
    },
    {
        group: 'Inventory',
        items: [
            'Multiple yards',
            'Movement ledger for every change',
            'Colour runs and lots',
            'Purchase orders and receiving',
            'Average cost on receipt',
            'Transfers between yards',
            'Cycle counts',
            'Stock adjustments with reasons',
        ],
    },
    {
        group: 'Rentals',
        items: [
            'Fleet and serialized units',
            'Availability calendar',
            'Hourly, daily, weekend, weekly and monthly rates',
            'Deposits, fuel and cleaning fees',
            'Agreements signed in person or by link',
            'Check-out and check-in inspections',
            'Service intervals and lockouts',
            'Online booking page',
        ],
    },
    {
        group: 'Money',
        items: [
            'Invoices by terms',
            'Aging and statements',
            'Returns and restocking fees',
            'Credit notes',
            'Pallet deposit ledger',
            'Card payments and payment links',
            'Sales tax by Stripe Tax',
            'Audit log of changes',
        ],
    },
];

const compare: { feature: string; sheet: string; pos: string; erp: string }[] =
    [
        {
            feature: 'Sells by the pallet, tonne and yard',
            sheet: 'Partly',
            pos: 'No',
            erp: 'Yes',
        },
        {
            feature: 'Contractor and job pricing',
            sheet: 'Manual',
            pos: 'Limited',
            erp: 'Yes',
        },
        {
            feature: 'Credit limits and statements',
            sheet: 'Manual',
            pos: 'No',
            erp: 'Yes',
        },
        {
            feature: 'Dispatch board and ETA texts',
            sheet: 'No',
            pos: 'No',
            erp: 'Add-on',
        },
        {
            feature: 'Equipment rentals in the same system',
            sheet: 'No',
            pos: 'No',
            erp: 'Rarely',
        },
        {
            feature: 'Pallet deposit tracking',
            sheet: 'Manual',
            pos: 'No',
            erp: 'Sometimes',
        },
        {
            feature: 'Contractor self-serve portal',
            sheet: 'No',
            pos: 'No',
            erp: 'Rarely',
        },
        {
            feature: 'Runs in a browser on any tablet or phone',
            sheet: 'Yes',
            pos: 'Yes',
            erp: 'Often not',
        },
    ];

const faqs: { q: string; a: string }[] = [
    {
        q: 'Do I need Shopify?',
        a: 'No. Yardline is the system of record for products, prices, accounts and orders, and works on its own. If you sell online with Shopify, Yardline pushes your products (every selling unit as a variant) and stock to it.',
    },
    {
        q: 'Does it replace my accounting software?',
        a: 'No. Yardline handles invoices, payments, aging and statements for your accounts. Your books stay in your accounting software. QuickBooks Online sync is on the way.',
    },
    {
        q: 'What hardware do I need?',
        a: 'Anything with a modern browser. The counter works on a desktop or tablet, the yard kiosk on a rugged tablet, and drivers use their phones. Pick tickets, invoices and statements print on regular paper.',
    },
    {
        q: 'We have more than one yard. Does that work?',
        a: 'Yes. Each yard has its own stock, bins and trucks. Accounts, prices and credit are shared, and you can transfer stock between yards.',
    },
    {
        q: 'How is tax handled?',
        a: 'Stripe Tax works out sales tax on every order, including HST and GST/QST in Canada. Accounts can be tax exempt with their exemption number on file.',
    },
    {
        q: 'Can contractors see other contractors’ orders?',
        a: 'No. Each contact sees only their organization’s orders, statement and deliveries, and only signs in with a link sent to their own email.',
    },
    {
        q: 'Who can change prices or credit limits?',
        a: 'Owners and admins. Counter staff can sell and take payments but can’t change prices or override a credit hold, and every price and credit change is in the audit log.',
    },
];

const ctaPrimary =
    'inline-flex h-11 items-center rounded-md bg-primary px-5 font-medium text-primary-foreground';
const ctaSecondary =
    'inline-flex h-11 items-center rounded-md border border-border bg-card px-5 font-medium hover:border-foreground';

export default function Welcome({ demo, canRegister }: Props) {
    const { auth } = usePage().props;
    const signedIn = Boolean(auth.user);

    const primary = demo ? (
        <Link href="/demo" className={ctaPrimary}>
            Try the demo yard
        </Link>
    ) : signedIn ? (
        <Link href={dashboard()} className={ctaPrimary}>
            Open Yardline
        </Link>
    ) : canRegister ? (
        <Link href={register()} className={ctaPrimary}>
            Set up your yard
        </Link>
    ) : null;

    return (
        <>
            <Head title="Yardline: inventory, orders and rentals for masonry and landscape supply yards" />
            <div className="min-h-screen bg-background text-foreground">
                <header className="sticky top-0 z-20 border-b border-transparent bg-background/90 backdrop-blur supports-[backdrop-filter]:bg-background/75">
                    <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                        <Link href="/" className="flex items-center gap-2">
                            <AppLogoIcon className="size-7 fill-current" />
                            <span className="text-xl font-bold tracking-tight">
                                Yardline
                            </span>
                        </Link>
                        <nav className="hidden items-center gap-1 text-sm lg:flex">
                            {nav.map((item) => (
                                <a
                                    key={item.href}
                                    href={item.href}
                                    className="rounded-md px-3 py-2 hover:bg-secondary"
                                >
                                    {item.label}
                                </a>
                            ))}
                        </nav>
                        <div className="flex items-center gap-1 text-sm sm:gap-2">
                            {signedIn ? (
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
                        </div>
                    </div>
                </header>

                <main>
                    {/* Hero */}
                    <section className="mx-auto grid w-full max-w-6xl gap-12 px-4 pt-10 pb-20 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:items-center lg:pt-16">
                        <div>
                            <Eyebrow>
                                For masonry and landscape supply yards
                            </Eyebrow>
                            <h1 className="mt-4 text-4xl leading-[1.05] font-bold tracking-tight sm:text-6xl">
                                Every pallet, tonne and yard of it, accounted
                                for.
                            </h1>
                            <div
                                className="tape-rule mt-8 max-w-xl"
                                aria-hidden="true"
                            />
                            <p className="mt-6 max-w-xl text-lg text-muted-foreground">
                                Yardline runs the counter, the yard, the trucks,
                                the rental fleet and the receivables in one
                                system, built around how a supply yard actually
                                sells: by the bag, the pallet, the tonne and the
                                yard, on account, with a deposit on every
                                pallet.
                            </p>
                            <div className="mt-8 flex flex-wrap gap-3">
                                {primary}
                                <a href="#day" className={ctaSecondary}>
                                    See a day in the yard
                                </a>
                            </div>
                            {demo && (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    No sign-up. Pick a role (owner, office,
                                    counter, yard lead) and click around a yard
                                    with a season of orders in it.
                                </p>
                            )}
                        </div>
                        <QueueMock />
                    </section>

                    {/* Who it's for */}
                    <section className="border-y border-border bg-card">
                        <div className="mx-auto grid w-full max-w-6xl gap-6 px-4 py-8 text-sm sm:px-6 md:grid-cols-[auto_1fr] md:items-center md:gap-10">
                            <span className="font-semibold">
                                Built for yards that sell
                            </span>
                            <ul className="flex flex-wrap gap-x-6 gap-y-2 text-muted-foreground">
                                {[
                                    'Pavers and wall block',
                                    'Natural stone',
                                    'Brick and block',
                                    'Mortar and masonry supplies',
                                    'Aggregates and soil',
                                    'Mulch and bulk landscape',
                                    'Equipment rentals',
                                ].map((item) => (
                                    <li
                                        key={item}
                                        className="flex items-center gap-2"
                                    >
                                        <span
                                            className="size-1.5 rounded-full bg-primary"
                                            aria-hidden="true"
                                        />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>

                    {/* Problem */}
                    <section className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
                        <Eyebrow>Sound familiar?</Eyebrow>
                        <h2 className="mt-3 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                            Generic software counts boxes. A yard doesn’t sell
                            boxes.
                        </h2>
                        <p className="mt-4 max-w-2xl text-muted-foreground">
                            Retail point of sale can’t sell half a pallet or put
                            a builder on net 30. Big ERPs can, after a six-month
                            implementation and a server in the back office. Most
                            yards end up somewhere in between, with a POS, a
                            spreadsheet and a whiteboard.
                        </p>
                        <div className="mt-10 grid gap-4 sm:grid-cols-2">
                            {pains.map((pain) => (
                                <div
                                    key={pain.title}
                                    className="rounded-lg border border-border bg-card p-6"
                                >
                                    <h3 className="font-semibold">
                                        {pain.title}
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {pain.body}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>

                    {/* Day in the yard */}
                    <section
                        id="day"
                        className="scroll-mt-20 bg-foreground text-background"
                    >
                        <div className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
                            <Eyebrow>A day in the yard</Eyebrow>
                            <h2 className="mt-3 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                                One order, from a phone on a job site to a
                                statement in the mail.
                            </h2>
                            <ol className="mt-12 grid gap-0 md:grid-cols-2 lg:grid-cols-1">
                                {day.map((step) => (
                                    <li
                                        key={step.time}
                                        className="grid grid-cols-[4.5rem_1fr] gap-4 border-t border-background/15 py-5 lg:grid-cols-[6rem_9rem_1fr_2fr] lg:items-baseline"
                                    >
                                        <span className="font-mono text-lg font-semibold text-primary">
                                            {step.time}
                                        </span>
                                        <div className="lg:contents">
                                            <span className="text-xs font-semibold tracking-widest uppercase opacity-60">
                                                {step.who}
                                            </span>
                                            <h3 className="mt-1 font-semibold lg:mt-0">
                                                {step.title}
                                            </h3>
                                            <p className="mt-1 text-sm opacity-75 lg:mt-0">
                                                {step.body}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </section>

                    {/* Deep dives */}
                    <div id="features" className="scroll-mt-20">
                        <Feature
                            eyebrow="Pricing and units"
                            title="The right price, in the unit they asked for"
                            body="Set up a product once in the unit you count it in, then sell it by the layer, the pallet, the bag or the tonne. Yardline converts every sale back to the base unit, so stock is always right."
                            points={[
                                'Contract prices for a builder’s job beat the price list, which beats retail',
                                'Price lists by selling unit, with quantity breaks and start and end dates',
                                'Margin shown as you price; flagged when a supplier’s cost goes up',
                                'Quotes hold their price until they expire and are accepted from a link',
                            ]}
                            visual={<PricingMock />}
                        />
                        <Feature
                            eyebrow="Dispatch and delivery"
                            title="A dispatch board instead of a whiteboard"
                            body="Every delivery, rental drop and pallet pickup on one board, trucks down the side and hours across. Drag a stop onto a truck and the customer gets a text with their window."
                            points={[
                                'Truck capacity and boom shown while you schedule',
                                'Delivery fees priced by zone, by distance or by truckload',
                                'Partial loads tracked line by line until the order is complete',
                                'Proof of delivery with a signature and photo, attached to the order',
                            ]}
                            visual={<DispatchMock />}
                            flip
                        />
                        <Feature
                            eyebrow="Inventory and purchasing"
                            title="Stock you can trust, at every yard"
                            body="Every receipt, sale, transfer, return and count is a line in the ledger, so on-hand numbers always add up. Pavers are kept by colour run, so a patio ships from one run and matches."
                            points={[
                                'Purchase orders to suppliers, received into runs, average cost updated',
                                'Transfers between yards',
                                'Cycle counts that post only the difference',
                                'Bins on pick tickets so the yard finds it first time',
                            ]}
                            visual={<StockMock />}
                        />
                        <section id="rentals" className="scroll-mt-20">
                            <Feature
                                eyebrow="Equipment rental"
                                title="Rent the compactor with the pavers"
                                body="A full rental module, not a bolt-on. Book units against a calendar, price the cheapest mix of rates, get the agreement signed and check it back in, all on the same account as the material order."
                                points={[
                                    'Hourly, half-day, daily, weekend, weekly and monthly rates; the cheapest mix wins',
                                    'Optional free Sunday with a Saturday pickup',
                                    'Agreements signed on a tablet or from a texted link, with damage waiver',
                                    'Hour meters and service intervals; units due for service are locked out',
                                    'An online booking page for homeowners',
                                ]}
                                visual={<RentalMock />}
                                flip
                            />
                        </section>
                        <section id="portal" className="scroll-mt-20">
                            <Feature
                                eyebrow="Contractor portal"
                                title="Fewer phone calls, more reorders"
                                body="Your account customers get their own portal. They sign in with a link sent to their email, so there’s nothing to install and no password to forget."
                                points={[
                                    'Reorder a past job in one tap; it lands as a draft for the counter',
                                    'Patio calculator turns square feet into pavers, base and sand',
                                    'Delivery status and ETA for today’s loads',
                                    'Statement, open invoices and pallets on site',
                                    'Request a pallet pickup; it goes straight onto the dispatch board',
                                ]}
                                visual={<PortalMock />}
                            />
                        </section>
                        <Feature
                            eyebrow="Receivables"
                            title="Get paid for everything that went out the gate"
                            body="Account orders are invoiced the moment they’re confirmed and come due by the account’s terms. Returns, credits and pallet deposits all land on the same statement."
                            points={[
                                'Credit limits checked at the counter; holds need an owner to override',
                                'Aging by account; statements to print, download or email',
                                'Returns restocked less a fee and credited to the account',
                                'Pallet deposits charged going out and credited coming back',
                                'Card payments and payment links by text or email through Stripe',
                            ]}
                            visual={<AgingMock />}
                            flip
                        />
                    </div>

                    {/* Devices */}
                    <section className="border-y border-border bg-card">
                        <div className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
                            <Eyebrow>
                                Everyone gets the screen they need
                            </Eyebrow>
                            <h2 className="mt-3 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                                Built for the counter, the yard and the cab.
                            </h2>
                            <div className="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                                <Role
                                    title="Counter"
                                    device="Desktop or tablet"
                                >
                                    Ring up walk-ins, take orders on account,
                                    quote jobs and take payments.
                                </Role>
                                <Role title="Yard" device="Kiosk tablet">
                                    Big buttons for gloves: the pick queue,
                                    bins, colour runs, loading and rental
                                    check-in.
                                </Role>
                                <Role title="Drivers" device="Their phone">
                                    The delivery address, site notes and PO
                                    number, then a signature and photo at the
                                    curb.
                                </Role>
                                <Role title="Office" device="Desktop">
                                    Prices, credit, receivables, purchasing and
                                    reports, with every change logged.
                                </Role>
                            </div>
                        </div>
                    </section>

                    {/* Everything */}
                    <section className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
                        <Eyebrow>Everything in it</Eyebrow>
                        <h2 className="mt-3 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                            One system, not six.
                        </h2>
                        <div className="mt-10 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                            {everything.map((group) => (
                                <div key={group.group}>
                                    <h3 className="border-b border-border pb-2 font-semibold">
                                        {group.group}
                                    </h3>
                                    <ul className="mt-3 grid gap-2 text-sm">
                                        {group.items.map((item) => (
                                            <li
                                                key={item}
                                                className="flex gap-2"
                                            >
                                                <Check />
                                                <span>{item}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </section>

                    {/* Compare */}
                    <section className="mx-auto w-full max-w-6xl px-4 pb-20 sm:px-6">
                        <div className="rounded-lg border border-border bg-card">
                            <div className="p-6 sm:p-8">
                                <h2 className="text-2xl font-bold tracking-tight">
                                    How it compares
                                </h2>
                                <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                                    Against what most yards run today. Your
                                    mileage with any one product will vary.
                                </p>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[40rem] text-sm">
                                    <thead>
                                        <tr className="border-y border-border text-left">
                                            <th className="px-6 py-3 font-medium sm:px-8" />
                                            <th className="px-3 py-3 font-semibold text-primary">
                                                Yardline
                                            </th>
                                            <th className="px-3 py-3 font-medium text-muted-foreground">
                                                Spreadsheets
                                            </th>
                                            <th className="px-3 py-3 font-medium text-muted-foreground">
                                                Retail POS
                                            </th>
                                            <th className="px-3 py-3 pr-6 font-medium text-muted-foreground sm:pr-8">
                                                Legacy ERP
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {compare.map((row) => (
                                            <tr key={row.feature}>
                                                <td className="px-6 py-3 font-medium sm:px-8">
                                                    {row.feature}
                                                </td>
                                                <td className="px-3 py-3">
                                                    <Chip tone="success">
                                                        Yes
                                                    </Chip>
                                                </td>
                                                <td className="px-3 py-3 text-muted-foreground">
                                                    {row.sheet}
                                                </td>
                                                <td className="px-3 py-3 text-muted-foreground">
                                                    {row.pos}
                                                </td>
                                                <td className="px-3 py-3 pr-6 text-muted-foreground sm:pr-8">
                                                    {row.erp}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    {/* Trust */}
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
                            <Point title="In the cloud, nothing to install">
                                Runs in the browser on any desktop, tablet or
                                phone. No server in the back office, no updates
                                to run.
                            </Point>
                            <Point title="Your data stays yours">
                                Each business is kept separate, and the yard
                                owner decides who on the team sees what.
                            </Point>
                            <Point title="Canadian from day one">
                                Prices in dollars, HST and GST/QST worked out by
                                Stripe Tax, metric and imperial units side by
                                side.
                            </Point>
                        </div>
                    </section>

                    {/* Integrations */}
                    <section className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
                        <Eyebrow>Integrations</Eyebrow>
                        <h2 className="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">
                            Works with what you already use
                        </h2>
                        <div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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

                    {/* Getting started */}
                    <section className="border-y border-border bg-card">
                        <div className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
                            <Eyebrow>Getting started</Eyebrow>
                            <h2 className="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">
                                Open the gate in an afternoon.
                            </h2>
                            <ol className="mt-10 grid gap-6 md:grid-cols-3">
                                <StepCard n="1" title="Set up your yards">
                                    Add your yards, bins, trucks and delivery
                                    zones, and the people who work the counter
                                    and the yard.
                                </StepCard>
                                <StepCard n="2" title="Load your catalogue">
                                    Products with their selling units and
                                    prices, your contractor and builder price
                                    lists, and the rental fleet.
                                </StepCard>
                                <StepCard n="3" title="Add your accounts">
                                    Contractors and builders with their credit
                                    limits, terms, job sites and contacts. Then
                                    take your first order.
                                </StepCard>
                            </ol>
                        </div>
                    </section>

                    {/* FAQ */}
                    <section
                        id="faq"
                        className="mx-auto w-full max-w-3xl scroll-mt-20 px-4 py-20 sm:px-6"
                    >
                        <h2 className="text-3xl font-bold tracking-tight sm:text-4xl">
                            Questions
                        </h2>
                        <div className="mt-8 divide-y divide-border border-y border-border">
                            {faqs.map((faq) => (
                                <details key={faq.q} className="group py-4">
                                    <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 font-medium">
                                        {faq.q}
                                        <span
                                            className="text-xl text-muted-foreground transition-transform group-open:rotate-45"
                                            aria-hidden="true"
                                        >
                                            +
                                        </span>
                                    </summary>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {faq.a}
                                    </p>
                                </details>
                            ))}
                        </div>
                    </section>

                    {/* CTA */}
                    <section className="mx-auto w-full max-w-6xl px-4 pb-24 sm:px-6">
                        <div className="rounded-lg bg-primary p-8 text-primary-foreground sm:p-12">
                            <h2 className="max-w-xl text-3xl font-bold tracking-tight sm:text-4xl">
                                {demo
                                    ? 'Walk the demo yard.'
                                    : 'Ready to count it all?'}
                            </h2>
                            <p className="mt-3 max-w-xl opacity-90">
                                {demo
                                    ? 'Escarpment Stone & Landscape Supply has two yards, a truck fleet, a rental fleet and a season of orders. Sign in as anyone on the team, or as a contractor in the portal.'
                                    : 'Set up your yards, catalogue and accounts, and take your first order today.'}
                            </p>
                            <div className="mt-6 flex flex-wrap gap-3">
                                {demo ? (
                                    <Link
                                        href="/demo"
                                        className="inline-flex h-11 items-center rounded-md bg-background px-5 font-medium text-foreground"
                                    >
                                        Try the demo yard
                                    </Link>
                                ) : (
                                    canRegister &&
                                    !signedIn && (
                                        <Link
                                            href={register()}
                                            className="inline-flex h-11 items-center rounded-md bg-background px-5 font-medium text-foreground"
                                        >
                                            Set up your yard
                                        </Link>
                                    )
                                )}
                                {!signedIn && (
                                    <Link
                                        href={login()}
                                        className="inline-flex h-11 items-center rounded-md border border-primary-foreground/40 px-5 font-medium"
                                    >
                                        Log in
                                    </Link>
                                )}
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-border">
                    <div className="mx-auto grid w-full max-w-6xl gap-6 px-4 py-10 text-sm sm:px-6 md:grid-cols-[1fr_auto]">
                        <div>
                            <div className="flex items-center gap-2">
                                <AppLogoIcon className="size-5 fill-current" />
                                <span className="font-bold">Yardline</span>
                            </div>
                            <p className="mt-2 text-muted-foreground">
                                Inventory, orders and rentals for masonry and
                                landscape supply yards.
                            </p>
                        </div>
                        <nav className="flex flex-wrap gap-x-5 gap-y-2 text-muted-foreground">
                            {nav.map((item) => (
                                <a
                                    key={item.href}
                                    href={item.href}
                                    className="hover:text-foreground"
                                >
                                    {item.label}
                                </a>
                            ))}
                            {demo && (
                                <Link
                                    href="/demo"
                                    className="hover:text-foreground"
                                >
                                    Demo
                                </Link>
                            )}
                            <Link
                                href={login()}
                                className="hover:text-foreground"
                            >
                                Log in
                            </Link>
                        </nav>
                    </div>
                </footer>
            </div>
        </>
    );
}

function Eyebrow({ children }: { children: ReactNode }) {
    return (
        <p className="font-mono text-xs font-semibold tracking-widest text-primary uppercase">
            {children}
        </p>
    );
}

function Feature({
    eyebrow,
    title,
    body,
    points,
    visual,
    flip = false,
}: {
    eyebrow: string;
    title: string;
    body: string;
    points: string[];
    visual: ReactNode;
    flip?: boolean;
}) {
    return (
        <section className="mx-auto grid w-full max-w-6xl items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:gap-16 lg:py-20">
            <div className={flip ? 'lg:order-2' : undefined}>
                <Eyebrow>{eyebrow}</Eyebrow>
                <h2 className="mt-3 text-3xl font-bold tracking-tight">
                    {title}
                </h2>
                <p className="mt-4 text-muted-foreground">{body}</p>
                <ul className="mt-6 grid gap-2.5 text-sm">
                    {points.map((point) => (
                        <li key={point} className="flex gap-2">
                            <Check />
                            <span>{point}</span>
                        </li>
                    ))}
                </ul>
            </div>
            <div className={`min-w-0 ${flip ? 'lg:order-1' : ''}`}>
                {visual}
            </div>
        </section>
    );
}

function Check() {
    return (
        <svg
            viewBox="0 0 16 16"
            className="mt-0.5 size-4 shrink-0 text-primary"
            aria-hidden="true"
        >
            <path
                d="M3 8.5l3 3 7-7"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function Role({
    title,
    device,
    children,
}: {
    title: string;
    device: string;
    children: ReactNode;
}) {
    return (
        <div className="rounded-lg border border-border bg-background p-5">
            <div className="font-mono text-xs text-muted-foreground">
                {device}
            </div>
            <h3 className="mt-1 font-semibold">{title}</h3>
            <p className="mt-2 text-sm text-muted-foreground">{children}</p>
        </div>
    );
}

function StepCard({
    n,
    title,
    children,
}: {
    n: string;
    title: string;
    children: ReactNode;
}) {
    return (
        <li className="rounded-lg border border-border bg-background p-6">
            <span className="flex size-9 items-center justify-center rounded-full bg-primary font-mono font-semibold text-primary-foreground">
                {n}
            </span>
            <h3 className="mt-4 font-semibold">{title}</h3>
            <p className="mt-2 text-sm text-muted-foreground">{children}</p>
        </li>
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
