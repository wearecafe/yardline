import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import {
    AgingMock,
    DispatchMock,
    DriverMock,
    PortalMock,
    PricingMock,
    QueueMock,
    RentalMock,
    StockMock,
    TimeMock,
} from '@/components/marketing/mocks';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { formatWholeMoney } from '@/lib/format';
import { dashboard, login, register } from '@/routes';

type Pricing = {
    currency: string;
    trial_days: number;
    items: {
        key: 'base' | 'rentals' | 'time';
        name: string;
        amount_cents: number;
        per_user: boolean;
    }[];
};

type Props = {
    demo: boolean;
    canRegister: boolean;
    pricing: Pricing;
};

// The header keeps to the essentials; the footer lists every section.
const topNav = [
    { href: '#features', label: 'Features' },
    { href: '#pricing', label: 'Pricing' },
    { href: '#faq', label: 'FAQ' },
    { href: '/help', label: 'Help' },
];

const nav = [
    { href: '#features', label: 'Features' },
    { href: '#rentals', label: 'Rentals' },
    { href: '#portal', label: 'Contractor portal' },
    { href: '#drivers', label: 'Driver app' },
    { href: '#time', label: 'Time clock' },
    { href: '#day', label: 'A day in the yard' },
    { href: '#pricing', label: 'Pricing' },
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
        time: '6:30',
        who: 'Yard',
        title: 'Owen clocks in at the counter tablet',
        body: 'His PIN on the tablet by the door, no punch cards. Sam clocks in from the driver app on his phone before he heads out.',
    },
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
        body: 'Dragged onto the board at 9 o’clock, with Sam picked to drive Boom 1 today. Mike gets a text with a two-hour window.',
    },
    {
        time: '10:40',
        who: 'Driver',
        title: 'Signed for at the curb',
        body: 'Sam’s phone shows his run in order. Mike watched the truck on the map once he was next, then signed on Sam’s screen. Pallet deposits go on the account automatically.',
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
        title: 'Lisa runs statements and approves hours',
        body: 'Aging by account, statements emailed, and Halton’s pallet pickup scheduled for tomorrow. Friday she fixes one missed clock-out, approves the week and sends the hours to payroll.',
    },
];

const everything: { group: string; items: string[] }[] = [
    {
        group: 'Units and pricing',
        items: [
            'Selling units with conversion factors',
            'Fractional and bulk quantities',
            'Units not sold on their own, rounded to the nearest mix',
            'Contract prices by organization',
            'Price lists with dates and quantity breaks',
            'Category discounts in $ or %',
            'Products exempt from discounts',
        ],
    },
    {
        group: 'Selling',
        items: [
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
            'Pick and load stages you switch on or off',
            'Second-person sign-off at Picked or Loaded',
            'Bin locations',
            'Partial shipments',
            'Truck capacities in tonnes, yards or pallets',
            'Trucks for material, rentals or both',
            'Dispatch board by hour',
            'Drivers picked per truck, per day',
            'Zone, distance or truckload fees',
            'ETA text messages',
            'Driver app on their own phone',
            'Live truck locations on the board',
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
            'Reminder texts and emails, overdue alerts',
            'Online booking page',
            'Contractors book on their own account',
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
            'Pallet usage fees',
            'Card payments and payment links',
            'Sales tax by Stripe Tax',
            'Gross profit by category, product, customer and unit',
            'Audit log of changes',
        ],
    },
    {
        group: 'Team',
        items: [
            'Job-based roles, several per person',
            'Permissions per person, refund limits per role',
            'Staff scoped to their yards',
            'PIN switching on shared tablets',
            'Installs to the home screen, with alerts',
            'Time clock on a tablet with PINs',
            'Punches from phones and the driver app',
            'Phone punches checked against each yard',
            'Weekly overtime for Ontario and Quebec',
            'Missed punches and short rests flagged',
            'Corrections with a reason, originals kept',
            'Payroll export to QuickBooks, ADP or CSV',
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
            feature: 'Driver app and live truck locations',
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
            feature: 'Time clock and payroll hours for the crew',
            sheet: 'Manual',
            pos: 'Sometimes',
            erp: 'Add-on',
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
        q: 'Do I need a separate point of sale?',
        a: 'No. YardRunner is your counter, order desk and contractor portal in one, so products, prices, accounts and orders all live in one place.',
    },
    {
        q: 'Does it replace my accounting software?',
        a: 'No. YardRunner handles invoices, payments, aging and statements for your accounts. Your books stay in QuickBooks Online: invoices, payments, credit memos and rental invoices are sent there as they happen, and payments you enter in QuickBooks come back to YardRunner.',
    },
    {
        q: 'What hardware do I need?',
        a: 'Anything with a modern browser. The counter works on a desktop or tablet, the yard kiosk on a rugged tablet, and drivers use their phones. Pick tickets, invoices and statements print on regular paper.',
    },
    {
        q: 'Do drivers need an app? How does truck tracking work?',
        a: 'No app store. Drivers sign in on their phone’s browser and see only their own truck’s run. While that page is open, the phone shares its location with the dispatch board about once a minute; it stops when they close it or lock the phone, and positions are kept for 30 days. You choose whether contractors see “stops before yours” and the truck on a map in their portal; the truck only shows once their delivery is next.',
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
        a: 'You decide. Everyone gets job-based roles (Sales, Rental desk, Yard crew, Dispatcher, Driver, Accounts and more), and judgment calls are separate permissions you can give to anyone: setting line prices by hand, overriding a credit limit, refunding above their role’s limit, seeing costs and margins. Out of the box, Sales can sell and take payments but can’t change prices or override a credit hold. Products you mark exempt from discounts can only be repriced by the roles you choose, with a reason. Every grant and every use is in the audit log.',
    },
    {
        q: 'Can someone check a load before it leaves the yard?',
        a: 'Yes. Choose which stages your yard uses (Picking, Picked, Loaded, Out for delivery), then require a sign-off at Picked, Loaded or both, by the roles you pick and by someone other than whoever did the work. A rejected load goes back a stage with the reason, and pick slips show who signed off.',
    },
    {
        q: 'We sell interlock by the layer and the pallet, never the single paver. Can it handle that?',
        a: 'Yes. Switch off the units you don’t sell on their own and they still count stock. Ask for 300 sq ft and YardRunner offers the nearest mix, like 2 pallets and 7 layers, rounding up or down as you choose for that product. Staff confirm it before it goes on the order, and the portal and patio calculator round the same way.',
    },
    {
        q: 'Does it track hours for the yard crew and drivers?',
        a: 'Yes, with the time and attendance add-on. Staff clock in on the counter tablet with a PIN, on their phone, or from the driver app. YardRunner works out weekly overtime for Ontario and Quebec, flags missed punches, and exports approved hours to QuickBooks Online, ADP or a spreadsheet.',
    },
];

const ctaPrimary =
    'inline-flex h-11 items-center rounded-md bg-primary px-5 font-medium text-primary-foreground';
const ctaSecondary =
    'inline-flex h-11 items-center rounded-md border border-border bg-card px-5 font-medium hover:border-foreground';

export default function Welcome({ demo, canRegister, pricing }: Props) {
    const { auth } = usePage().props;
    const signedIn = Boolean(auth.user);

    const primary = demo ? (
        <Link href="/demo" className={ctaPrimary}>
            Try the demo yard
        </Link>
    ) : signedIn ? (
        <Link href={dashboard()} className={ctaPrimary}>
            Open YardRunner
        </Link>
    ) : canRegister ? (
        <Link href={register()} className={ctaPrimary}>
            Set up your yard
        </Link>
    ) : null;

    // The trial button repeats at a few decision points down the page.
    const trialCta =
        canRegister && !signedIn ? (
            <Link href={register()} className={ctaPrimary}>
                Start your free trial
            </Link>
        ) : null;

    return (
        <>
            <Head title="YardRunner: inventory, orders and rentals for masonry and landscape supply yards" />
            <div className="min-h-screen bg-background text-foreground">
                <header className="sticky top-0 z-20 border-b border-transparent bg-background/90 backdrop-blur supports-[backdrop-filter]:bg-background/75">
                    <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                        <Link href="/" className="flex items-center gap-2">
                            <AppLogoIcon className="size-7 fill-current" />
                            <span className="text-xl font-bold tracking-tight">
                                YardRunner
                            </span>
                        </Link>
                        <nav className="hidden items-center gap-1 text-sm md:flex">
                            {topNav.map((item) => (
                                <a
                                    key={item.href}
                                    href={item.href}
                                    className="rounded-md px-3 py-2 whitespace-nowrap hover:bg-secondary"
                                >
                                    {item.label}
                                </a>
                            ))}
                        </nav>
                        <div className="flex items-center gap-1 text-sm sm:gap-2">
                            {signedIn ? (
                                <Link
                                    href={dashboard()}
                                    className="rounded-md bg-primary px-4 py-2.5 font-medium whitespace-nowrap text-primary-foreground"
                                >
                                    Open YardRunner
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="rounded-md px-3 py-2.5 whitespace-nowrap hover:bg-secondary"
                                    >
                                        Log in
                                    </Link>
                                    {demo ? (
                                        <Link
                                            href="/demo"
                                            className="rounded-md bg-primary px-4 py-2.5 font-medium whitespace-nowrap text-primary-foreground"
                                        >
                                            Try the demo
                                        </Link>
                                    ) : (
                                        canRegister && (
                                            <Link
                                                href={register()}
                                                className="rounded-md bg-primary px-4 py-2.5 font-medium whitespace-nowrap text-primary-foreground"
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
                                YardRunner runs the counter, the yard, the
                                trucks, the rental fleet, the crew’s hours and
                                the receivables in one system, built around how
                                a supply yard actually sells: by the bag, the
                                pallet, the tonne and the yard, on account, with
                                a deposit on every pallet.
                            </p>
                            <div className="mt-8 flex flex-wrap gap-3">
                                {primary}
                                <a href="#features" className={ctaSecondary}>
                                    See what it does
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

                    {/* Deep dives */}
                    <div id="features" className="scroll-mt-20">
                        <Feature
                            eyebrow="Pricing and units"
                            title="The right price, in the unit they asked for"
                            body="Set up a product once in the unit you count it in, then sell it by the layer, the pallet, the bag or the tonne. YardRunner converts every sale back to the base unit, so stock is always right."
                            points={[
                                'Contract prices for a builder’s job beat the price list, which beats retail',
                                'Price lists by selling unit, with quantity breaks and start and end dates',
                                'Take $ or % off a whole category, like 10% off wall block for your builders',
                                'Mark a product exempt and it always sells at its own price, discounts or not',
                                'Sell interlock only by the layer or pallet; 300 sq ft rounds to the nearest mix',
                                'Margin shown as you price; flagged when a supplier’s cost goes up',
                                'Quotes hold their price until they expire and are accepted from a link',
                            ]}
                            visual={<PricingMock />}
                        />
                        <Feature
                            eyebrow="Receivables"
                            title="Get paid for everything that went out the gate"
                            body="Account orders are invoiced the moment they’re confirmed and come due by the account’s terms. Returns, credits and pallet deposits all land on the same statement."
                            points={[
                                'Credit limits checked at the counter; holds need an owner to override',
                                'Aging by account; statements to print, download or email',
                                'Returns restocked less a fee and credited to the account',
                                'Pallet deposits charged going out and credited coming back',
                                'Or a non-refundable usage fee per pallet, on its own line so customers see what comes back',
                                'Card payments and payment links by text or email through Stripe',
                                'Gross profit by category, product, customer or unit, with a drill-down to every line',
                            ]}
                            visual={<AgingMock />}
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
                        <Feature
                            eyebrow="Dispatch and delivery"
                            title="A dispatch board instead of a whiteboard"
                            body="Every delivery, rental drop and pallet pickup on one board, trucks down the side and hours across. Drag a stop onto a truck and the customer gets a text with their window."
                            points={[
                                'Truck capacity in tonnes, yards or pallets, and boom, shown while you schedule',
                                'Pick who drives each truck each day from the drivers allowed on it',
                                'Switch on only the pick and load stages your yard uses',
                                'Require a second person to sign off a load at Picked or Loaded',
                                'Delivery fees priced by zone, by distance or by truckload',
                                'Partial loads tracked line by line until the order is complete',
                                'Proof of delivery with a signature and photo, attached to the order',
                            ]}
                            visual={<DispatchMock />}
                            flip
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
                                    'Reminder texts and emails before pickup and return; the rental desk is alerted when a unit is overdue',
                                    'An online booking page for homeowners, with a calendar of what’s free',
                                    'Contractors signed in to the portal book on their own account, licence and insurance already on file',
                                ]}
                                visual={<RentalMock />}
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
                                    'Delivery status and ETA for today’s loads, with how many stops are before theirs',
                                    'The truck on a map once their delivery is next (if you turn it on)',
                                    'Statement, open invoices and pallets on site',
                                    'Request a pallet pickup; it goes straight onto the dispatch board',
                                    'Rent equipment on their account without calling the counter',
                                    'Made for phones: tabs along the bottom and orders as cards',
                                ]}
                                visual={<PortalMock />}
                                flip
                            />
                        </section>
                        <section id="drivers" className="scroll-mt-20">
                            <Feature
                                eyebrow="Driver app"
                                title="Every driver’s run on their phone, every truck on the map"
                                body="Drivers sign in on their own phone and see their truck’s stops in order: address, site notes, PO, what’s on the truck. One tap for directions, one to call the site, and the signature at the curb. While their run is open, the phone shares where the truck is."
                                points={[
                                    'Each driver sees only their own truck. No prices, no accounts, nothing to break',
                                    '“On my way” with an ETA; rental drops and pallet pickups finished in a tap',
                                    'Dispatch sees where every truck was last seen, right on the board',
                                    'Optional for contractors: “2 stops before yours” in the portal, and the truck on a map once theirs is next',
                                    'Clock in and out from the same app, if you use the time clock',
                                    'Nothing to install. It runs in the phone’s browser and adds to the home screen',
                                ]}
                                visual={<DriverMock />}
                            />
                        </section>
                        <section id="time" className="scroll-mt-20">
                            <Feature
                                eyebrow="Time and attendance · add-on"
                                title="Hours from the yard, the counter and the cab"
                                body="Retire the punch clock. The crew clocks in on the counter tablet with a PIN, office staff on their phone, and drivers from the same app that runs their deliveries. At the end of the period, approve the hours and send them to payroll."
                                points={[
                                    'Phone punches checked against each yard; drivers who take a truck home are excused',
                                    'No signal? Punches wait on the phone and go through with the right time',
                                    'Breaks, rounding and weekly overtime for Ontario and Quebec worked out for you',
                                    'Missed clock-outs, long shifts and short rests flagged on Who’s in',
                                    'Corrections need a reason, keep the original punch, and the employee can dispute them',
                                    'Approve and lock each pay period, then export to QuickBooks Online, ADP or a spreadsheet',
                                ]}
                                visual={<TimeMock />}
                                flip
                            />
                        </section>
                    </div>

                    {/* Compare */}
                    <section className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
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
                                                YardRunner
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
                            {trialCta && (
                                <div className="flex flex-wrap items-center justify-between gap-4 border-t border-border p-6 sm:px-8">
                                    <p className="text-sm font-medium">
                                        See how it compares with your own
                                        catalogue and accounts.
                                    </p>
                                    {trialCta}
                                </div>
                            )}
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
                                name="Stripe"
                                status="Available"
                                tone="success"
                            >
                                Card payments and payment links by text or
                                email. Set up payouts, bank details and disputes
                                in Settings, with no separate Stripe account.
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
                                status="Available"
                                tone="success"
                            >
                                Invoices, payments, credits and rental invoices
                                posted to your books as they happen, and
                                approved hours sent as time activities.
                            </Integration>
                            <Integration
                                name="ADP"
                                status="Payroll export"
                                tone="info"
                            >
                                Approved hours exported as an ADP import file at
                                the end of each pay period.
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
                                    zones, and invite the people who work the
                                    counter, the yard and the trucks.
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
                            {trialCta && (
                                <div className="mt-10 flex flex-wrap items-center gap-x-4 gap-y-2">
                                    {trialCta}
                                    <p className="text-sm text-muted-foreground">
                                        {pricing.trial_days} days free with the
                                        add-ons switched on. No card to start.
                                    </p>
                                </div>
                            )}
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
                                Sales, Rental desk, Yard crew, Dispatcher,
                                Driver, Accounts and more, several to a person
                                if they wear two hats. Each works their own
                                yards, the yard crew never see prices, and
                                drivers see their own run and nothing else.
                            </Point>
                            <Point title="In the cloud, nothing to set up">
                                Runs in the browser on any desktop, tablet or
                                phone, and installs to the home screen with its
                                own icon. No server in the back office, no
                                updates to run.
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
                                    quote jobs and take payments. The same
                                    tablet is the crew’s time clock.
                                </Role>
                                <Role title="Yard" device="Kiosk tablet">
                                    Big buttons for gloves: the pick queue,
                                    bins, colour runs, loading and rental
                                    check-in. The crew switch users with their
                                    PIN.
                                </Role>
                                <Role title="Drivers" device="Their phone">
                                    Their own run in order, directions and site
                                    notes, a signature at the curb, the truck’s
                                    location shared with dispatch, and their
                                    clock-in.
                                </Role>
                                <Role title="Office" device="Desktop">
                                    Prices, credit, receivables, purchasing,
                                    timesheets and reports, with every change
                                    logged.
                                </Role>
                            </div>
                            <p className="mt-6 max-w-3xl text-sm text-muted-foreground">
                                One app for everyone: install it to the home
                                screen of any tablet or phone and it opens
                                straight to that person’s screen. Turn on alerts
                                and the yard hears about new orders to pick,
                                checkers about loads waiting for sign-off,
                                drivers about new stops, and the rental desk
                                about overdue units.
                            </p>
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

                    {/* Everything */}
                    <section className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
                        <Eyebrow>Everything in it</Eyebrow>
                        <h2 className="mt-3 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                            One system, not six.
                        </h2>
                        <div className="mt-10 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-4">
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

                    <PricingSection pricing={pricing} cta={trialCta} />

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
                                <span className="font-bold">YardRunner</span>
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

const pricingCopy: Record<
    Pricing['items'][number]['key'],
    { title: string; label: string; points: string[] }
> = {
    base: {
        title: 'YardRunner',
        label: 'Everything a supply yard runs on',
        points: [
            'Counter sales, quotes and orders',
            'Picking, dispatch and the driver app',
            'Stock by yard, purchasing and colour runs',
            'Price lists, contract prices and credit',
            'Pallet deposits and usage fees, returns and receivables',
            'Gross-profit report with drill-down',
            'Contractor portal and QuickBooks Online',
            'Job-based roles and permissions',
            'Unlimited users',
        ],
    },
    rentals: {
        title: 'Rentals',
        label: 'Add-on',
        points: [
            'Fleet, calendar and rate mixing',
            'Agreements signed on a phone or tablet',
            'Deposits, inspections and maintenance',
            'Online booking page',
        ],
    },
    time: {
        title: 'Time and attendance',
        label: 'Add-on',
        points: [
            'Counter tablet, phone and driver-app time clock',
            'Timesheets and Ontario and Quebec overtime',
            'Exports for QuickBooks, ADP or any payroll',
        ],
    },
};

function PricingSection({
    pricing,
    cta,
}: {
    pricing: Pricing;
    cta: ReactNode;
}) {
    const money = (cents: number) => formatWholeMoney(cents, pricing.currency);
    const amount = (key: Pricing['items'][number]['key']) =>
        pricing.items.find((item) => item.key === key)?.amount_cents ?? 0;
    // A worked example: Rentals on, ten people on the clock.
    const example = amount('base') + amount('rentals') + amount('time') * 10;

    return (
        <section
            id="pricing"
            className="mx-auto w-full max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6"
        >
            <Eyebrow>Pricing</Eyebrow>
            <h2 className="mt-3 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                One subscription. Add what your yard does.
            </h2>
            <p className="mt-4 max-w-2xl text-muted-foreground">
                Every plan starts with a {pricing.trial_days}-day free trial
                with the add-ons switched on, and no card to start. Prices are
                monthly in {pricing.currency}, plus applicable taxes.
            </p>

            <div className="mt-10 grid gap-4 lg:grid-cols-[1.25fr_1fr_1fr]">
                {pricing.items.map((item) => {
                    const copy = pricingCopy[item.key];
                    const base = item.key === 'base';

                    return (
                        <div
                            key={item.key}
                            className={
                                base
                                    ? 'flex flex-col rounded-lg border-2 border-primary bg-card p-6'
                                    : 'flex flex-col rounded-lg border border-border bg-card p-6'
                            }
                        >
                            <p className="font-mono text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                                {copy.label}
                            </p>
                            <h3 className="mt-2 text-xl font-semibold">
                                {copy.title}
                            </h3>
                            <p className="mt-4 flex items-baseline gap-1">
                                <span className="text-4xl font-bold tracking-tight tabular-nums">
                                    {base ? '' : '+'}
                                    {money(item.amount_cents)}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    {item.per_user
                                        ? 'per user / month'
                                        : '/ month'}
                                </span>
                            </p>
                            {item.per_user && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    For each person whose time you track.
                                </p>
                            )}
                            <ul className="mt-6 grid gap-2 text-sm">
                                {copy.points.map((point) => (
                                    <li key={point} className="flex gap-2">
                                        <Check />
                                        <span>{point}</span>
                                    </li>
                                ))}
                            </ul>
                            {base && cta && (
                                <div className="mt-auto pt-8">{cta}</div>
                            )}
                        </div>
                    );
                })}
            </div>

            <p className="mt-6 text-sm text-muted-foreground">
                For example, a yard with rentals and ten people on the clock
                pays {money(example)} a month. Switch add-ons on or off any
                time; the next bill is adjusted for the days used.
            </p>
        </section>
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
