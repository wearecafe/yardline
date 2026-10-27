import { router, useForm } from '@inertiajs/react';
import { Check, ImageUp } from 'lucide-react';
import type { FormEvent } from 'react';
import { useRef, useState } from 'react';
import BrandingController from '@/actions/App/Http/Controllers/Settings/BrandingController';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import InputError from '@/components/input-error';
import { StepActions, StepHeader, StepSection } from '@/components/setup/step';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Props = {
    brand: { name: string; primary: string; logo_url: string | null };
    swatches: string[];
    logoMaxKb: number;
};

const HEX = /^#?[0-9a-f]{6}$/i;

/** Black or white, whichever reads on the colour. */
function textOn(hex: string): string {
    const n = parseInt(hex.replace('#', ''), 16);
    const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((c) => {
        const s = c / 255;

        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });
    const l = 0.2126 * r + 0.7152 * g + 0.0722 * b;

    return 1.05 / (l + 0.05) >= (l + 0.05) / 0.05 ? '#ffffff' : '#1c1917';
}

export default function SetupBrand({ brand, swatches, logoMaxKb }: Props) {
    const form = useForm({ primary: brand.primary.toUpperCase() });
    const input = useRef<HTMLInputElement>(null);
    const [logoError, setLogoError] = useState<string | undefined>();
    const [uploading, setUploading] = useState(false);
    const colour = HEX.test(form.data.primary)
        ? `#${form.data.primary.replace('#', '')}`
        : brand.primary;

    function upload(file: File | undefined) {
        if (!file) {
            return;
        }

        setUploading(true);
        router.post(
            BrandingController.storeLogo.url(),
            { logo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => setLogoError(errors.logo),
                onSuccess: () => setLogoError(undefined),
                onFinish: () => {
                    setUploading(false);

                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(SetupController.brand.url());
    }

    return (
        <>
            <StepHeader description="Your logo and colour go on every quote, invoice and email your customers get, so they come from your yard, not from software." />

            <form onSubmit={submit} className="grid gap-8">
                <StepSection
                    title="Logo"
                    description={`PNG or JPG on a white or solid background, at least 40 pixels tall, up to ${Math.round(logoMaxKb / 1024) || 1} MB.`}
                >
                    <div className="flex flex-wrap items-center gap-4">
                        <button
                            type="button"
                            onClick={() => input.current?.click()}
                            disabled={uploading}
                            className="flex h-20 w-60 items-center justify-center rounded-[10px] border border-dashed border-input bg-white px-4 transition-colors hover:border-primary"
                        >
                            {brand.logo_url ? (
                                <img
                                    src={brand.logo_url}
                                    alt={`${brand.name} logo`}
                                    className="max-h-14 max-w-full object-contain"
                                />
                            ) : (
                                <span className="flex items-center gap-2 text-sm font-medium text-neutral-600">
                                    <ImageUp className="size-4" />
                                    Choose a logo
                                </span>
                            )}
                        </button>
                        <input
                            ref={input}
                            type="file"
                            accept="image/png,image/jpeg"
                            className="sr-only"
                            aria-label="Logo"
                            onChange={(e) => upload(e.target.files?.[0])}
                        />
                        {brand.logo_url && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={uploading}
                                onClick={() => input.current?.click()}
                            >
                                Replace
                            </Button>
                        )}
                    </div>
                    <InputError message={logoError} />
                </StepSection>

                <StepSection
                    title="Brand colour"
                    description="Used for buttons, links and the bar along the top of your emails. Text on it is picked to stay readable."
                >
                    <div className="flex flex-wrap items-center gap-2.5">
                        {swatches.map((swatch) => {
                            const selected =
                                colour.toLowerCase() === swatch.toLowerCase();

                            return (
                                <button
                                    key={swatch}
                                    type="button"
                                    aria-label={`Use ${swatch}`}
                                    aria-pressed={selected}
                                    onClick={() =>
                                        form.setData('primary', swatch)
                                    }
                                    className={cn(
                                        'flex size-9 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition-shadow',
                                        selected
                                            ? 'ring-2 ring-foreground'
                                            : 'hover:ring-2 hover:ring-input',
                                    )}
                                    style={{ backgroundColor: swatch }}
                                >
                                    {selected && (
                                        <Check
                                            className="size-4"
                                            strokeWidth={3}
                                            style={{ color: textOn(swatch) }}
                                        />
                                    )}
                                </button>
                            );
                        })}
                        <label className="ml-1 flex items-center gap-2">
                            <span className="sr-only">Colour code</span>
                            <input
                                type="color"
                                value={colour}
                                onChange={(e) =>
                                    form.setData(
                                        'primary',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                className="size-9 cursor-pointer rounded-md border border-input bg-card p-1"
                            />
                            <Input
                                value={form.data.primary}
                                onChange={(e) =>
                                    form.setData('primary', e.target.value)
                                }
                                className="figure w-28 uppercase"
                                maxLength={7}
                                aria-invalid={!!form.errors.primary}
                            />
                        </label>
                    </div>
                    <InputError message={form.errors.primary} />
                </StepSection>

                <StepSection title="How it looks">
                    <EmailPreview
                        name={brand.name}
                        logo={brand.logo_url}
                        colour={colour}
                    />
                </StepSection>

                <StepActions processing={form.processing} />
            </form>
        </>
    );
}

/** A sketch of the top of a quote email. Settings → Branding has the real one. */
function EmailPreview({
    name,
    logo,
    colour,
}: {
    name: string;
    logo: string | null;
    colour: string;
}) {
    return (
        <div
            aria-hidden
            className="overflow-hidden rounded-[10px] border border-border bg-[#f5f5f4] p-4 sm:p-6"
        >
            <div className="mx-auto max-w-md overflow-hidden rounded-lg bg-white text-[#1c1917] shadow-sm">
                <div className="h-1.5" style={{ backgroundColor: colour }} />
                <div className="flex h-14 items-center px-5">
                    {logo ? (
                        <img
                            src={logo}
                            alt=""
                            className="max-h-8 max-w-[60%] object-contain"
                        />
                    ) : (
                        <span className="text-sm font-bold">{name}</span>
                    )}
                </div>
                <div className="grid gap-3 border-t border-[#e7e5e4] px-5 py-5">
                    <p className="text-base font-bold">Your quote is ready</p>
                    <div className="grid gap-1.5">
                        <div className="h-2 w-11/12 rounded bg-[#e7e5e4]" />
                        <div className="h-2 w-3/4 rounded bg-[#e7e5e4]" />
                    </div>
                    <span
                        className="mt-1 inline-flex w-fit rounded-md px-4 py-2 text-sm font-semibold"
                        style={{
                            backgroundColor: colour,
                            color: textOn(colour),
                        }}
                    >
                        View and accept quote
                    </span>
                </div>
            </div>
        </div>
    );
}
