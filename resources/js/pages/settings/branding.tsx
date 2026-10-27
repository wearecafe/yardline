import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import BrandingController from '@/actions/App/Http/Controllers/Settings/BrandingController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import { Field, NativeSelect } from '@/components/yard/field';
import {
    checks as contrastChecks,
    generate,
    normalize,
    resolve,
} from '@/lib/colour-system';
import { cn } from '@/lib/utils';

type Props = {
    brand: {
        name: string;
        primary: string;
        overrides: Record<string, string>;
        header_style: string;
        footer_note: string;
        tax_numbers: string;
        logo_url: string | null;
        colour_system: boolean;
        sent_with_yardrunner: boolean;
        sender: string;
    };
    tokens: { key: string; label: string; use: string }[];
    headerStyles: { value: string; label: string }[];
    previewUrl: string;
};

const PRESETS = [
    '#1F5C99',
    '#2F6B3B',
    '#A33A22',
    '#2C7A7B',
    '#F2B705',
    '#2B2B28',
];

function Swatch({ colour, className }: { colour: string; className?: string }) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-block shrink-0 rounded-md border border-black/10',
                className,
            )}
            style={{ background: colour }}
        />
    );
}

/** Debounces a value, so the preview isn't redrawn on every keystroke. */
function useSettled(value: string, ms = 350): string {
    const [settled, setSettled] = useState(value);

    useEffect(() => {
        const timer = window.setTimeout(() => setSettled(value), ms);

        return () => window.clearTimeout(timer);
    }, [value, ms]);

    return settled;
}

function LogoSection({ brand }: Pick<Props, 'brand'>) {
    const input = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    const upload = (file: File | undefined) => {
        if (!file) {
            return;
        }

        setBusy(true);
        router.post(
            BrandingController.storeLogo.url(),
            { logo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) => setError(errors.logo),
                onSuccess: () => setError(undefined),
                onFinish: () => {
                    setBusy(false);

                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <section className="grid gap-3">
            <h2 className="text-sm font-semibold">Logo</h2>
            <div className="flex flex-wrap items-center gap-4">
                <div className="flex h-16 w-52 items-center justify-center rounded-md border border-dashed border-input bg-white px-3">
                    {brand.logo_url ? (
                        <img
                            src={brand.logo_url}
                            alt={`${brand.name} logo`}
                            className="max-h-12 max-w-full object-contain"
                        />
                    ) : (
                        <span className="text-sm font-semibold text-neutral-800">
                            {brand.name}
                        </span>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    <input
                        ref={input}
                        id="logo"
                        type="file"
                        accept="image/png,image/jpeg"
                        className="sr-only"
                        onChange={(e) => upload(e.target.files?.[0])}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={() => input.current?.click()}
                    >
                        {brand.logo_url ? 'Replace logo' : 'Upload logo'}
                    </Button>
                    {brand.logo_url && (
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={busy}
                            onClick={() =>
                                router.delete(
                                    BrandingController.destroyLogo.url(),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Remove
                        </Button>
                    )}
                </div>
            </div>
            <p className="text-xs text-muted-foreground">
                PNG or JPG, at least 40 pixels tall, up to 2 MB. A logo on a
                white or solid background works best in every email app. Without
                one, your business name is shown.
            </p>
            <InputError message={error} />
        </section>
    );
}

export default function Branding({
    brand,
    tokens,
    headerStyles,
    previewUrl,
}: Props) {
    const form = useForm({
        primary: brand.primary,
        header_style: brand.header_style,
        footer_note: brand.footer_note,
        tax_numbers: brand.tax_numbers,
        overrides: { ...brand.overrides } as Record<string, string>,
    });
    const [primaryDraft, setPrimaryDraft] = useState(brand.primary);
    const [drafts, setDrafts] = useState<Record<string, string>>({
        ...brand.overrides,
    });

    const overrides = useMemo(
        () => (brand.colour_system ? form.data.overrides : {}),
        [brand.colour_system, form.data.overrides],
    );
    const generated = useMemo(
        () => generate(form.data.primary),
        [form.data.primary],
    );
    const resolved = useMemo(
        () => resolve(form.data.primary, overrides),
        [form.data.primary, overrides],
    );
    const checks = useMemo(() => contrastChecks(resolved), [resolved]);
    const failing = brand.colour_system
        ? checks.filter((c) => c.required && !c.passes).length
        : 0;

    const setPrimary = (value: string) => {
        setPrimaryDraft(value);
        const hex = normalize(value);

        if (hex) {
            form.setData('primary', hex);
        }
    };

    const setToken = (key: string, value: string) => {
        setDrafts((d) => ({ ...d, [key]: value }));
        const hex = normalize(value);

        if (hex) {
            form.setData('overrides', { ...form.data.overrides, [key]: hex });
        }
    };

    const resetToken = (key: string) => {
        const next = { ...form.data.overrides };
        delete next[key];
        form.setData('overrides', next);
        setDrafts((d) => {
            const copy = { ...d };
            delete copy[key];

            return copy;
        });
    };

    const liveSrc = useMemo(() => {
        const params = new URLSearchParams({
            primary: form.data.primary,
            header_style: form.data.header_style,
            footer_note: form.data.footer_note,
            tax_numbers: form.data.tax_numbers,
        });

        Object.entries(overrides).forEach(([key, value]) =>
            params.set(`overrides[${key}]`, value),
        );

        return `${previewUrl}?${params.toString()}`;
    }, [
        form.data.primary,
        form.data.header_style,
        form.data.footer_note,
        form.data.tax_numbers,
        overrides,
        previewUrl,
    ]);
    const previewSrc = useSettled(liveSrc);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(BrandingController.update.url(), {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    }

    return (
        <>
            <Head title="Branding" />
            <h1 className="sr-only">Branding</h1>
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Branding"
                    description="How your business looks on the emails your customers and suppliers get: quotes, invoices, statements, delivery updates and rentals."
                />

                <div className="grid gap-10 xl:grid-cols-[minmax(0,36rem)_minmax(22rem,30rem)] xl:items-start">
                    <div className="grid content-start gap-8">
                        <LogoSection brand={brand} />

                        <form onSubmit={submit} className="grid gap-8">
                            <section className="grid gap-3">
                                <h2 className="text-sm font-semibold">
                                    Primary colour
                                </h2>
                                <div className="flex flex-wrap items-end gap-3">
                                    <Swatch
                                        colour={form.data.primary}
                                        className="size-11"
                                    />
                                    <Field
                                        label="Hex"
                                        htmlFor="primary"
                                        error={form.errors.primary}
                                    >
                                        <Input
                                            id="primary"
                                            value={primaryDraft}
                                            onChange={(e) =>
                                                setPrimary(e.target.value)
                                            }
                                            className="w-32 font-mono"
                                            maxLength={7}
                                        />
                                    </Field>
                                    <div className="grid gap-1.5">
                                        <span className="text-sm text-muted-foreground">
                                            Or pick one
                                        </span>
                                        <div className="flex flex-wrap gap-2">
                                            {PRESETS.map((hex) => (
                                                <button
                                                    key={hex}
                                                    type="button"
                                                    aria-label={`Use ${hex}`}
                                                    onClick={() =>
                                                        setPrimary(hex)
                                                    }
                                                    className={cn(
                                                        'size-11 rounded-md border',
                                                        form.data.primary ===
                                                            hex
                                                            ? 'ring-2 ring-foreground ring-offset-2'
                                                            : 'border-black/10',
                                                    )}
                                                    style={{ background: hex }}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                </div>
                                {!brand.colour_system && (
                                    <p className="text-xs text-muted-foreground">
                                        We build readable shades of your colour
                                        for you, so text and buttons are always
                                        easy to read. Need control over every
                                        colour? Ask YardRunner support to turn
                                        on the full colour system.
                                    </p>
                                )}
                            </section>

                            {brand.colour_system && (
                                <section className="grid gap-3">
                                    <div className="flex flex-wrap items-baseline justify-between gap-3">
                                        <div>
                                            <h2 className="text-sm font-semibold">
                                                Colour system
                                            </h2>
                                            <p className="text-xs text-muted-foreground">
                                                Built from your primary. Type a
                                                hex to change any colour;
                                                changed colours are kept if you
                                                change the primary.
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                form.setData('overrides', {});
                                                setDrafts({});
                                            }}
                                        >
                                            Reset all to generated
                                        </Button>
                                    </div>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {tokens.map((token) => {
                                            const custom =
                                                token.key in
                                                form.data.overrides;

                                            return (
                                                <div
                                                    key={token.key}
                                                    className="flex items-center gap-3 rounded-md border p-2"
                                                >
                                                    <Swatch
                                                        colour={
                                                            resolved[token.key]
                                                        }
                                                        className="size-9"
                                                    />
                                                    <div className="grid min-w-0 flex-1">
                                                        <span className="flex items-center gap-1.5 text-sm font-medium">
                                                            {token.label}
                                                            <Chip
                                                                tone={
                                                                    custom
                                                                        ? 'warning'
                                                                        : 'neutral'
                                                                }
                                                            >
                                                                {custom
                                                                    ? 'Custom'
                                                                    : 'Generated'}
                                                            </Chip>
                                                        </span>
                                                        <span className="truncate text-xs text-muted-foreground">
                                                            {token.use}
                                                        </span>
                                                    </div>
                                                    <label
                                                        className="sr-only"
                                                        htmlFor={`token-${token.key}`}
                                                    >
                                                        {token.label} hex
                                                    </label>
                                                    <Input
                                                        id={`token-${token.key}`}
                                                        value={
                                                            drafts[token.key] ??
                                                            generated[token.key]
                                                        }
                                                        onChange={(e) =>
                                                            setToken(
                                                                token.key,
                                                                e.target.value,
                                                            )
                                                        }
                                                        className="h-8 w-24 font-mono text-xs"
                                                        maxLength={7}
                                                    />
                                                    {custom && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            aria-label={`Reset ${token.label} to generated`}
                                                            onClick={() =>
                                                                resetToken(
                                                                    token.key,
                                                                )
                                                            }
                                                        >
                                                            Reset
                                                        </Button>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                    <InputError
                                        message={form.errors.overrides}
                                    />

                                    <div className="grid gap-1">
                                        <h3 className="text-sm font-semibold">
                                            Readability checks
                                        </h3>
                                        {checks.map((check) => (
                                            <div
                                                key={check.key}
                                                className="flex items-center gap-3 border-t py-1.5 text-sm"
                                            >
                                                <span
                                                    aria-hidden="true"
                                                    className="flex h-7 w-11 items-center justify-center rounded border border-black/10 text-xs font-bold"
                                                    style={{
                                                        background: check.bg,
                                                        color: check.fg,
                                                    }}
                                                >
                                                    Aa
                                                </span>
                                                <span className="flex-1">
                                                    {check.label}
                                                    <span className="text-muted-foreground">
                                                        {check.required
                                                            ? ' · needs 4.5:1'
                                                            : ' · aim for 4.5:1'}
                                                    </span>
                                                </span>
                                                <span className="font-mono text-xs">
                                                    {check.ratio.toFixed(1)}:1
                                                </span>
                                                <Chip
                                                    tone={
                                                        check.passes
                                                            ? 'success'
                                                            : check.required
                                                              ? 'danger'
                                                              : 'warning'
                                                    }
                                                >
                                                    {check.passes
                                                        ? 'Pass'
                                                        : check.required
                                                          ? 'Fails'
                                                          : 'Low'}
                                                </Chip>
                                            </div>
                                        ))}
                                    </div>
                                </section>
                            )}

                            <section className="grid gap-4">
                                <Field
                                    label="Header style"
                                    htmlFor="header_style"
                                    error={form.errors.header_style}
                                >
                                    <NativeSelect
                                        id="header_style"
                                        value={form.data.header_style}
                                        onChange={(e) =>
                                            form.setData(
                                                'header_style',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {headerStyles.map((style) => (
                                            <option
                                                key={style.value}
                                                value={style.value}
                                            >
                                                {style.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field
                                    label="Tax registration numbers"
                                    htmlFor="tax_numbers"
                                    error={form.errors.tax_numbers}
                                    hint="Shown with your business name and address at the bottom of each email, e.g. HST 81234 5678 RT0001."
                                >
                                    <Input
                                        id="tax_numbers"
                                        value={form.data.tax_numbers}
                                        maxLength={120}
                                        onChange={(e) =>
                                            form.setData(
                                                'tax_numbers',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Footer note"
                                    htmlFor="footer_note"
                                    error={form.errors.footer_note}
                                    hint="Optional. A line under every email, like a slogan or your terms."
                                >
                                    <Input
                                        id="footer_note"
                                        value={form.data.footer_note}
                                        maxLength={300}
                                        onChange={(e) =>
                                            form.setData(
                                                'footer_note',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </section>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="submit"
                                    disabled={form.processing || failing > 0}
                                >
                                    Save branding
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            BrandingController.test.url(),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Send me a test email
                                </Button>
                                {failing > 0 && (
                                    <span className="text-sm text-destructive">
                                        Fix {failing} failing{' '}
                                        {failing === 1 ? 'check' : 'checks'} to
                                        save.
                                    </span>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Customer emails come from {brand.sender}. Change
                                this under Settings → Sending domain. The test
                                email uses your saved branding.
                            </p>
                        </form>
                    </div>

                    <aside className="grid max-w-xl content-start gap-2 xl:sticky xl:top-6">
                        <span className="text-sm font-medium">
                            Preview · Order confirmation
                        </span>
                        <iframe
                            title="Email preview"
                            src={previewSrc}
                            sandbox=""
                            className="h-[760px] w-full rounded-lg border bg-white"
                        />
                    </aside>
                </div>
            </div>
        </>
    );
}

Branding.layout = {
    breadcrumbs: [{ title: 'Branding', href: BrandingController.edit() }],
    wide: true,
};
