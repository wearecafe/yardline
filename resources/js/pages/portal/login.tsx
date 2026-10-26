import { Head, router, useForm } from '@inertiajs/react';
import PortalAuthController from '@/actions/App/Http/Controllers/Portal/PortalAuthController';
import InputError from '@/components/input-error';

type Props = {
    yard: { name: string; slug: string };
    demo: { id: number; first_name: string; last_name: string | null } | null;
    sent: string | null;
};

/**
 * Contractor sign-in: an email with a link, no password.
 */
export default function PortalLogin({ yard, demo, sent }: Props) {
    const form = useForm({ email: '' });

    return (
        <>
            <Head title={`Sign in · ${yard.name}`} />
            <div className="grid min-h-svh place-items-center bg-background px-4">
                <main className="w-full max-w-sm rounded-[10px] border border-border bg-card p-6">
                    <div className="text-sm font-semibold text-[#9a3412]">
                        {yard.name}
                    </div>
                    <h1 className="mt-1 text-2xl font-bold">
                        Contractor account
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Orders, deliveries, statements and pallets on deposit.
                    </p>

                    {sent ? (
                        <p className="mt-5 rounded-lg bg-success-soft px-3 py-3 text-sm text-success">
                            If {sent} is on an account, a sign-in link is on its
                            way. It works for 30 minutes.
                        </p>
                    ) : (
                        <form
                            className="mt-5 grid gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(
                                    PortalAuthController.send.url(yard.slug),
                                );
                            }}
                        >
                            <label className="grid gap-1">
                                <span className="text-sm font-medium">
                                    Email
                                </span>
                                <input
                                    type="email"
                                    required
                                    autoComplete="email"
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                    className="h-11 rounded-lg border border-input bg-card px-3"
                                />
                                <InputError message={form.errors.email} />
                            </label>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="h-11 rounded-lg bg-primary font-semibold text-white hover:bg-[#9a3412] disabled:opacity-50"
                            >
                                Email me a sign-in link
                            </button>
                        </form>
                    )}

                    {demo && (
                        <div className="mt-6 border-t border-border pt-4">
                            <p className="text-sm text-muted-foreground">
                                This is the demo yard.
                            </p>
                            <button
                                type="button"
                                onClick={() =>
                                    router.post(
                                        PortalAuthController.demo.url(
                                            yard.slug,
                                        ),
                                    )
                                }
                                className="mt-2 h-11 w-full rounded-lg border border-input font-semibold hover:bg-accent"
                            >
                                Continue as {demo.first_name} {demo.last_name}
                            </button>
                        </div>
                    )}
                </main>
            </div>
        </>
    );
}
