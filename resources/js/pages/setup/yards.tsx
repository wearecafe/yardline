import { useForm } from '@inertiajs/react';
import { MapPin, Plus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import { StepActions, StepHeader } from '@/components/setup/step';
import { Field, NativeSelect } from '@/components/yard/field';
import { Notice } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Yard = {
    id: number | null;
    name: string;
    code: string;
    address_line1: string;
    address_line2: string;
    city: string;
    region: string;
    postal_code: string;
    country: 'CA' | 'US';
    /** The code was typed, so stop suggesting one from the name. */
    code_edited?: boolean;
};

type Props = {
    yards: {
        id: number;
        name: string;
        code: string;
        address_line1: string | null;
        address_line2: string | null;
        city: string | null;
        region: string | null;
        postal_code: string | null;
        country: string;
    }[];
    provinces: { value: string; label: string }[];
};

/** "Burlington North" → "BN", "Main yard" → "MAIN". */
function suggestCode(name: string): string {
    const words = name
        .toUpperCase()
        .replace(/[^A-Z0-9 ]/g, '')
        .split(/\s+/)
        .filter((w) => w && w !== 'YARD');

    if (words.length === 0) {
        return '';
    }

    return (
        words.length === 1
            ? words[0].slice(0, 6)
            : words.map((w) => w[0]).join('')
    ).slice(0, 8);
}

const blank = (): Yard => ({
    id: null,
    name: '',
    code: '',
    address_line1: '',
    address_line2: '',
    city: '',
    region: 'ON',
    postal_code: '',
    country: 'CA',
});

export default function SetupYards({ yards, provinces }: Props) {
    const form = useForm<{ yards: Yard[] }>({
        yards: yards.length
            ? yards.map((y) => ({
                  id: y.id,
                  name: y.name,
                  code: y.code,
                  address_line1: y.address_line1 ?? '',
                  address_line2: y.address_line2 ?? '',
                  city: y.city ?? '',
                  region: y.region ?? 'ON',
                  postal_code: y.postal_code ?? '',
                  country: y.country === 'US' ? 'US' : 'CA',
                  code_edited: true,
              }))
            : [blank()],
    });
    const errors = form.errors as Record<string, string | undefined>;

    function change(index: number, patch: Partial<Yard>) {
        form.setData(
            'yards',
            form.data.yards.map((yard, i) => {
                if (i !== index) {
                    return yard;
                }

                const next = { ...yard, ...patch };

                if (patch.name !== undefined && !yard.code_edited) {
                    next.code = suggestCode(patch.name);
                }

                return next;
            }),
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            yards: data.yards.map((yard) => {
                const copy = { ...yard };
                delete copy.code_edited;

                return copy;
            }),
        }));
        form.put(SetupController.yards.url());
    }

    return (
        <>
            <StepHeader description="Every place you keep stock and sell from. Pickup orders are taxed at the yard’s address, and delivery distances are measured from it." />
            <form onSubmit={submit} className="grid gap-5">
                {form.data.yards.map((yard, i) => {
                    const id = (field: string) => `yard-${i}-${field}`;
                    const error = (field: string) =>
                        errors[`yards.${i}.${field}`];

                    return (
                        <fieldset
                            key={yard.id ?? `new-${i}`}
                            className="grid gap-4 rounded-[10px] border border-border bg-card p-5"
                        >
                            <legend className="sr-only">Yard {i + 1}</legend>
                            <div className="flex items-center justify-between gap-3">
                                <div className="flex items-center gap-2 text-sm font-semibold">
                                    <MapPin className="size-4 text-primary" />
                                    {i === 0 ? 'Main yard' : `Yard ${i + 1}`}
                                </div>
                                {yard.id === null && i > 0 && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            form.setData(
                                                'yards',
                                                form.data.yards.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                    >
                                        <X />
                                        Remove
                                    </Button>
                                )}
                            </div>
                            <div className="grid grid-cols-[minmax(0,1fr)_7rem] gap-4">
                                <Field
                                    label="Yard name"
                                    htmlFor={id('name')}
                                    error={error('name')}
                                >
                                    <Input
                                        id={id('name')}
                                        value={yard.name}
                                        placeholder="Burlington yard"
                                        onChange={(e) =>
                                            change(i, { name: e.target.value })
                                        }
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Short code"
                                    htmlFor={id('code')}
                                    error={error('code')}
                                >
                                    <Input
                                        id={id('code')}
                                        value={yard.code}
                                        className="uppercase"
                                        maxLength={20}
                                        onChange={(e) =>
                                            change(i, {
                                                code: e.target.value,
                                                code_edited: true,
                                            })
                                        }
                                        required
                                    />
                                </Field>
                            </div>
                            <Field
                                label="Street address"
                                htmlFor={id('line1')}
                                error={error('address_line1')}
                            >
                                <Input
                                    id={id('line1')}
                                    value={yard.address_line1}
                                    autoComplete={
                                        i === 0 ? 'address-line1' : 'off'
                                    }
                                    onChange={(e) =>
                                        change(i, {
                                            address_line1: e.target.value,
                                        })
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                label={
                                    <>
                                        Unit, suite or lot{' '}
                                        <span className="font-normal text-muted-foreground">
                                            (optional)
                                        </span>
                                    </>
                                }
                                htmlFor={id('line2')}
                                error={error('address_line2')}
                            >
                                <Input
                                    id={id('line2')}
                                    value={yard.address_line2}
                                    onChange={(e) =>
                                        change(i, {
                                            address_line2: e.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_8rem]">
                                <Field
                                    label="City"
                                    htmlFor={id('city')}
                                    error={error('city')}
                                >
                                    <Input
                                        id={id('city')}
                                        value={yard.city}
                                        onChange={(e) =>
                                            change(i, { city: e.target.value })
                                        }
                                        required
                                    />
                                </Field>
                                <Field
                                    label={
                                        yard.country === 'CA'
                                            ? 'Province'
                                            : 'State'
                                    }
                                    htmlFor={id('region')}
                                    error={error('region')}
                                >
                                    {yard.country === 'CA' ? (
                                        <NativeSelect
                                            id={id('region')}
                                            value={yard.region}
                                            onChange={(e) =>
                                                change(i, {
                                                    region: e.target.value,
                                                })
                                            }
                                        >
                                            {provinces.map((p) => (
                                                <option
                                                    key={p.value}
                                                    value={p.value}
                                                >
                                                    {p.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    ) : (
                                        <Input
                                            id={id('region')}
                                            value={yard.region}
                                            maxLength={2}
                                            className="uppercase"
                                            placeholder="NY"
                                            onChange={(e) =>
                                                change(i, {
                                                    region: e.target.value,
                                                })
                                            }
                                            required
                                        />
                                    )}
                                </Field>
                                <Field
                                    label={
                                        yard.country === 'CA'
                                            ? 'Postal code'
                                            : 'ZIP code'
                                    }
                                    htmlFor={id('postal')}
                                    error={error('postal_code')}
                                >
                                    <Input
                                        id={id('postal')}
                                        value={yard.postal_code}
                                        className="uppercase"
                                        autoComplete={
                                            i === 0 ? 'postal-code' : 'off'
                                        }
                                        onChange={(e) =>
                                            change(i, {
                                                postal_code: e.target.value,
                                            })
                                        }
                                        required
                                    />
                                </Field>
                            </div>
                            <Field
                                label="Country"
                                htmlFor={id('country')}
                                error={error('country')}
                                className="sm:max-w-56"
                            >
                                <NativeSelect
                                    id={id('country')}
                                    value={yard.country}
                                    onChange={(e) => {
                                        const country = e.target.value as
                                            | 'CA'
                                            | 'US';
                                        change(i, {
                                            country,
                                            region:
                                                country === 'CA' ? 'ON' : '',
                                        });
                                    }}
                                >
                                    <option value="CA">Canada</option>
                                    <option value="US">United States</option>
                                </NativeSelect>
                            </Field>
                        </fieldset>
                    );
                })}

                <div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            form.setData('yards', [
                                ...form.data.yards,
                                {
                                    ...blank(),
                                    region: form.data.yards[0]?.region ?? 'ON',
                                    country:
                                        form.data.yards[0]?.country ?? 'CA',
                                },
                            ])
                        }
                    >
                        <Plus />
                        Add another yard
                    </Button>
                </div>

                {errors.yards && <Notice tone="danger">{errors.yards}</Notice>}

                <p className="text-sm text-muted-foreground">
                    This is the last required step. After it, YardRunner is open
                    and the rest of setup can wait.
                </p>

                <StepActions processing={form.processing} />
            </form>
        </>
    );
}
