import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, IdCard } from 'lucide-react';
import { useRef, useState } from 'react';
import RentalController from '@/actions/App/Http/Controllers/Rentals/RentalController';
import InputError from '@/components/input-error';
import { SignaturePad } from '@/components/yard/signature-pad';
import type { SignaturePadHandle } from '@/components/yard/signature-pad';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { RentalDetail } from '@/types';

type Props = {
    via: 'counter' | 'link';
    rental: RentalDetail;
    agreement: {
        terms: string;
        summary: Record<string, string | string[]>;
        signed_at: string | null;
        signer_name: string | null;
        pdf_url: string | null;
    };
    yard: string;
    licence: {
        id: number;
        expires_on: string | null;
        url: string | null;
    } | null;
    submitUrl: string;
    payUrl: string;
};

/**
 * Agreement and signing (mockup 4): review, ID, sign, pay. On the counter
 * tablet staff check the licence; on the texted link the customer signs and
 * shows the licence at pickup.
 */
export default function Agreement({
    via,
    rental,
    agreement,
    yard,
    licence,
    submitUrl,
    payUrl,
}: Props) {
    const pad = useRef<SignaturePadHandle>(null);
    const [padEmpty, setPadEmpty] = useState(true);
    const [licencePhoto, setLicencePhoto] = useState<File | null>(null);
    const signed = agreement.signed_at !== null;
    const paid = rental.balance_cents <= 0;
    const counter = via === 'counter';

    const form = useForm<{
        signer_name: string;
        signature: string;
        acknowledged_training: boolean;
        id_document_id: number | null;
        licence_photo: File | null;
    }>({
        signer_name: rental.customer?.name ?? '',
        signature: '',
        acknowledged_training: false,
        id_document_id: licence?.id ?? null,
        licence_photo: null,
    });

    const idDone = !counter || licence !== null || licencePhoto !== null;
    const step = signed
        ? paid
            ? 5
            : 4
        : !form.data.acknowledged_training
          ? 1
          : !idDone
            ? 2
            : 3;

    function sign() {
        form.transform((data) => ({
            ...data,
            signature: pad.current?.toDataUrl() ?? '',
            licence_photo: licencePhoto,
            id_document_id: licence?.id ?? null,
        }));
        form.post(submitUrl, {
            preserveScroll: true,
            forceFormData: licencePhoto !== null,
        });
    }

    const summary = agreement.summary;
    const rows: [string, string, boolean?][] = [
        ['Out', String(summary.out ?? '')],
        ['Due back', String(summary.due_back ?? ''), true],
        ['Rental total, incl. tax', formatMoney(rental.total_cents)],
        ['Deposit hold', formatMoney(rental.deposit_cents)],
        ['Late return', String(summary.late_return ?? '')],
        ['Damage waiver', String(summary.damage_waiver ?? '')],
    ];

    return (
        <>
            <Head title={`Rental agreement ${rental.number}`} />
            <div className="flex min-h-svh flex-col bg-background text-[15px]">
                <header className="flex flex-wrap items-center justify-between gap-3 bg-[#1c1917] px-6 py-4 text-[#fafaf9]">
                    <div>
                        <div className="text-xs tracking-[0.06em] text-[#d6d3d1] uppercase">
                            Rental agreement · {rental.number}
                            {!counter && ` · ${yard}`}
                        </div>
                        <div className="text-xl font-semibold">
                            {String(summary.customer ?? '')} ·{' '}
                            {(summary.equipment as string[] | undefined)?.join(
                                ', ',
                            )}
                        </div>
                    </div>
                    <ol
                        className="flex items-center gap-1.5 text-[13px] text-[#d6d3d1]"
                        aria-label="Steps"
                    >
                        {['Review', 'ID', 'Sign', 'Pay'].map((label, i) => {
                            const n = i + 1;
                            const done = step > n;
                            const current = step === n;

                            return (
                                <li
                                    key={label}
                                    aria-current={current ? 'step' : undefined}
                                    className={cn(
                                        'rounded-full px-2.5 py-1.5',
                                        done &&
                                            'bg-[#15803d] font-semibold text-white',
                                        current &&
                                            'bg-primary font-semibold text-white',
                                        !done &&
                                            !current &&
                                            'border border-[#57534e]',
                                    )}
                                >
                                    {n} {label}
                                </li>
                            );
                        })}
                    </ol>
                </header>

                <div className="flex flex-1 flex-wrap gap-5 px-6 py-5">
                    <section className="flex min-w-[300px] flex-1 flex-col gap-3 rounded-xl border border-border bg-card p-5">
                        <h2 className="text-[17px] font-semibold">
                            What you’re agreeing to
                        </h2>
                        <dl className="grid gap-2.5">
                            {rows.map(([label, value, bold]) => (
                                <div
                                    key={label}
                                    className="flex justify-between gap-4"
                                >
                                    <dt className="text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd
                                        className={cn(
                                            bold && 'font-semibold',
                                            label.includes('total') ||
                                                label.includes('Deposit')
                                                ? 'figure'
                                                : '',
                                        )}
                                    >
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <div className="max-h-72 min-h-0 flex-1 overflow-auto border-t border-border pt-3 text-sm leading-relaxed text-subtle">
                            <p className="mb-2.5 font-semibold text-foreground">
                                Full terms
                            </p>
                            <p className="whitespace-pre-line">
                                {agreement.terms}
                            </p>
                        </div>
                        {!signed && (
                            <label className="flex min-h-12 items-center gap-3 rounded-lg bg-accent-soft px-3">
                                <input
                                    type="checkbox"
                                    className="size-[22px] accent-primary"
                                    checked={form.data.acknowledged_training}
                                    onChange={(e) =>
                                        form.setData(
                                            'acknowledged_training',
                                            e.target.checked,
                                        )
                                    }
                                />
                                <span>
                                    I’ve read the terms and I’m trained to
                                    operate this equipment safely.
                                </span>
                            </label>
                        )}
                        <InputError
                            message={form.errors.acknowledged_training}
                        />
                    </section>

                    <section className="flex min-w-[300px] flex-1 flex-col gap-3.5">
                        {counter && (
                            <div className="flex items-center gap-3.5 rounded-xl border border-border bg-card p-4">
                                <div
                                    className="flex h-11 w-16 items-center justify-center rounded-md bg-border"
                                    aria-hidden="true"
                                >
                                    <IdCard className="size-6 text-muted-foreground" />
                                </div>
                                <div className="flex-1">
                                    {licence || licencePhoto ? (
                                        <>
                                            <div className="font-semibold">
                                                Driver’s licence{' '}
                                                {licencePhoto
                                                    ? 'photographed'
                                                    : 'on file'}
                                            </div>
                                            <div className="text-[13px] text-muted-foreground">
                                                Check the name matches the
                                                renter · photo saved to the
                                                contract
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            <label
                                                htmlFor="licence"
                                                className="font-semibold"
                                            >
                                                Photograph the driver’s licence
                                            </label>
                                            <input
                                                id="licence"
                                                type="file"
                                                accept="image/*"
                                                capture="environment"
                                                className="mt-1 block text-sm"
                                                onChange={(e) =>
                                                    setLicencePhoto(
                                                        e.target.files?.[0] ??
                                                            null,
                                                    )
                                                }
                                            />
                                        </>
                                    )}
                                    <InputError
                                        message={
                                            (
                                                form.errors as Record<
                                                    string,
                                                    string
                                                >
                                            ).licence_photo
                                        }
                                    />
                                </div>
                                {(licence || licencePhoto) && (
                                    <Check
                                        className="size-6 text-[#15803d]"
                                        strokeWidth={3}
                                        aria-label="Done"
                                    />
                                )}
                            </div>
                        )}

                        {signed ? (
                            <div className="flex flex-1 flex-col gap-3 rounded-xl border border-border bg-card p-5">
                                <div className="flex items-center gap-2 text-lg font-semibold text-success">
                                    <Check className="size-6" strokeWidth={3} />{' '}
                                    Signed by {agreement.signer_name}
                                </div>
                                {agreement.pdf_url && (
                                    <a
                                        href={agreement.pdf_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="font-medium text-primary hover:underline"
                                    >
                                        View the signed agreement (PDF)
                                    </a>
                                )}
                                {paid ? (
                                    <p className="text-success">
                                        Paid in full.{' '}
                                        {counter
                                            ? 'Ready to check the equipment out.'
                                            : 'See you at pickup. Bring your driver’s licence.'}
                                    </p>
                                ) : (
                                    <>
                                        <p>
                                            {formatMoney(rental.balance_cents)}{' '}
                                            to pay
                                            {counter && rental.customer?.card
                                                ? ` on ${rental.customer.card}`
                                                : ''}
                                            .
                                        </p>
                                        <button
                                            type="button"
                                            className="h-[52px] rounded-[10px] bg-primary text-[17px] font-bold text-white hover:bg-[#9a3412]"
                                            onClick={() =>
                                                router.post(
                                                    payUrl,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {counter
                                                ? rental.customer?.card
                                                    ? `Charge ${formatMoney(rental.balance_cents)} to ${rental.customer.card}`
                                                    : 'Take payment'
                                                : `Pay ${formatMoney(rental.balance_cents)}`}
                                        </button>
                                        {counter && !rental.customer?.card && (
                                            <Link
                                                href={RentalController.show(
                                                    rental.id,
                                                )}
                                                className="text-center text-sm text-primary hover:underline"
                                            >
                                                Record a counter payment on the
                                                rental
                                            </Link>
                                        )}
                                    </>
                                )}
                                {counter && (
                                    <Link
                                        href={RentalController.show(rental.id)}
                                        className="mt-auto text-sm text-muted-foreground hover:underline"
                                    >
                                        Back to {rental.number}
                                    </Link>
                                )}
                            </div>
                        ) : (
                            <div className="flex flex-1 flex-col gap-2.5 rounded-xl border border-border bg-card p-4">
                                <label className="grid gap-1">
                                    <span className="text-[13px] text-muted-foreground">
                                        Printed name
                                    </span>
                                    <input
                                        value={form.data.signer_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'signer_name',
                                                e.target.value,
                                            )
                                        }
                                        className="h-12 rounded-lg border border-input px-3 text-base"
                                        autoComplete="name"
                                    />
                                </label>
                                <InputError message={form.errors.signer_name} />
                                <div className="text-[13px] text-muted-foreground">
                                    Sign below
                                </div>
                                <div className="flex-1">
                                    <SignaturePad
                                        ref={pad}
                                        label="Signature"
                                        onChange={setPadEmpty}
                                    />
                                </div>
                                <InputError
                                    message={
                                        form.errors.signature ??
                                        (form.errors as Record<string, string>)
                                            .rental
                                    }
                                />
                                <div className="flex gap-2.5">
                                    <button
                                        type="button"
                                        className="h-[52px] rounded-[10px] border border-input bg-card px-4.5"
                                        onClick={() => pad.current?.clear()}
                                    >
                                        Clear
                                    </button>
                                    <button
                                        type="button"
                                        disabled={
                                            form.processing ||
                                            padEmpty ||
                                            !form.data.acknowledged_training ||
                                            !idDone ||
                                            form.data.signer_name.trim() === ''
                                        }
                                        className="h-[52px] flex-1 rounded-[10px] bg-primary text-[17px] font-bold text-white hover:bg-[#9a3412] disabled:opacity-50"
                                        onClick={sign}
                                    >
                                        Sign and continue to payment
                                    </button>
                                </div>
                            </div>
                        )}
                        <p className="text-xs text-muted-foreground">
                            A signed PDF is emailed to the customer and stored
                            on the contract with a time stamp and device record.
                        </p>
                    </section>
                </div>
            </div>
        </>
    );
}
