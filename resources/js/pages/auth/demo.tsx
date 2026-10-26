import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { login } from '@/routes';

type Props = {
    yard: string;
    accounts: {
        email: string;
        name: string;
        role: string;
        description: string;
    }[];
    portalUrl: string;
    bookingUrl: string;
};

export default function Demo({ yard, accounts, portalUrl, bookingUrl }: Props) {
    const [signingIn, setSigningIn] = useState<string | null>(null);

    function signIn(email: string) {
        setSigningIn(email);
        router.post('/demo', { email }, { onFinish: () => setSigningIn(null) });
    }

    return (
        <>
            <Head title="Demo yard" />
            <div className="grid gap-2">
                {accounts.map((account) => (
                    <button
                        key={account.email}
                        type="button"
                        disabled={signingIn !== null}
                        onClick={() => signIn(account.email)}
                        className="grid gap-0.5 rounded-md border border-border bg-card px-4 py-3 text-left transition-colors hover:border-foreground focus-visible:ring-[3px] focus-visible:ring-ring/30 focus-visible:outline-none disabled:opacity-60"
                    >
                        <span className="flex items-baseline justify-between gap-3">
                            <span className="font-medium">{account.name}</span>
                            <span className="text-xs text-muted-foreground">
                                {signingIn === account.email
                                    ? 'Signing in…'
                                    : account.role}
                            </span>
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {account.description}
                        </span>
                    </button>
                ))}
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                <a
                    href={portalUrl}
                    className="grid gap-0.5 rounded-md border border-dashed border-border px-4 py-3 text-left hover:border-foreground"
                >
                    <span className="font-medium">A contractor</span>
                    <span className="text-sm text-muted-foreground">
                        The customer portal: deliveries, reorders, statements.
                    </span>
                </a>
                <a
                    href={bookingUrl}
                    className="grid gap-0.5 rounded-md border border-dashed border-border px-4 py-3 text-left hover:border-foreground"
                >
                    <span className="font-medium">A homeowner</span>
                    <span className="text-sm text-muted-foreground">
                        The public page for renting equipment.
                    </span>
                </a>
            </div>
            <p className="text-center text-sm text-muted-foreground">
                {yard} is fictional and is rebuilt every night. Other demo
                visitors can see changes you make until then.
            </p>
            <p className="text-center text-sm">
                <Link
                    href={login()}
                    className="underline-offset-4 hover:underline"
                >
                    Back to log in
                </Link>
            </p>
        </>
    );
}

Demo.layout = {
    title: 'Try the demo yard',
    description:
        'Pick someone at Escarpment Stone to see Yardline through their eyes.',
};
