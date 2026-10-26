import { Head, Link, useForm } from '@inertiajs/react';
import { Camera, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import RentalController from '@/actions/App/Http/Controllers/Rentals/RentalController';
import { ToggleButtons } from '@/components/yard/toggle-buttons';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { RentalDetail } from '@/types';

type Props = {
    kind: 'check_out' | 'return';
    rental: RentalDetail;
    checkOut: Record<
        string,
        { hour_meter: string | null; fuel: string | null; photo_count: number }
    >;
    lateGraceMinutes: number;
    service: Record<
        string,
        { hours_until: number | null; interval: number | null } | null
    >;
};

type Fuel = 'full' | 'half' | 'low';

type Photo = {
    file: File;
    label: string;
    new_damage: boolean;
    preview: string;
};

type UnitState = {
    rental_item_id: number;
    hour_meter: string;
    fuel: Fuel;
    clean: boolean;
    notes: string;
    photos: Photo[];
    damage_description: string;
    damage_amount: string;
    damage_covered: boolean;
};

const photoLabels = ['Front', 'Left', 'Right', 'Back', 'Controls', 'Damage'];

/**
 * Check-out and return inspection (mockup 5): one screen per unit, built
 * for a phone in the yard.
 */
export default function Inspect({
    kind,
    rental,
    checkOut,
    lateGraceMinutes,
    service,
}: Props) {
    const isReturn = kind === 'return';
    const [current, setCurrent] = useState(0);
    const [units, setUnits] = useState<UnitState[]>(() =>
        rental.items.map((item) => ({
            rental_item_id: item.id,
            hour_meter: item.hour_meter ? String(Number(item.hour_meter)) : '',
            fuel: 'full',
            clean: true,
            notes: '',
            photos: [],
            damage_description: '',
            damage_amount: '',
            damage_covered: rental.damage_waiver,
        })),
    );
    const form = useForm({ deposit_tender: '' });

    useEffect(() => {
        return () =>
            units.forEach((u) =>
                u.photos.forEach((p) => URL.revokeObjectURL(p.preview)),
            );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const item = rental.items[current];
    const unit = units[current];
    const before = item?.rental_unit_id
        ? checkOut[String(item.rental_unit_id)]
        : undefined;

    function update(patch: Partial<UnitState>) {
        setUnits((all) =>
            all.map((u, i) => (i === current ? { ...u, ...patch } : u)),
        );
    }

    function addPhotos(files: FileList | null) {
        if (!files) {
            return;
        }

        const used = new Set(unit.photos.map((p) => p.label));
        const next = Array.from(files).map((file, i) => {
            const label =
                photoLabels.find((l) => !used.has(l) && l !== 'Damage') ??
                `Photo ${unit.photos.length + i + 1}`;
            used.add(label);

            return {
                file,
                label,
                new_damage: false,
                preview: URL.createObjectURL(file),
            };
        });
        update({ photos: [...unit.photos, ...next] });
    }

    const late = useMemo(() => {
        if (!isReturn) {
            return null;
        }

        const due =
            new Date(rental.ends_at).getTime() + lateGraceMinutes * 60_000;
        const over = Date.now() - due;

        return over > 0 ? Math.ceil(over / (24 * 3600_000)) : 0;
    }, [isReturn, rental.ends_at, lateGraceMinutes]);

    const fuelCharge =
        !rental.bill_fuel_cleaning || unit?.fuel === 'full'
            ? 0
            : unit?.fuel === 'half'
              ? Math.floor((item?.fuel_charge_cents ?? 0) / 2)
              : (item?.fuel_charge_cents ?? 0);
    const cleaningCharge =
        !rental.bill_fuel_cleaning || unit?.clean
            ? 0
            : (item?.cleaning_fee_cents ?? 0);
    const hoursNow = Number(unit?.hour_meter || 0);
    const svc = item ? service[String(item.id)] : null;
    const serviceDueNow =
        isReturn &&
        svc?.hours_until != null &&
        item?.hour_meter != null &&
        hoursNow - Number(item.hour_meter) >= svc.hours_until;

    function submit() {
        form.transform(() => ({
            deposit_tender: form.data.deposit_tender || null,
            units: units.map((u) => ({
                rental_item_id: u.rental_item_id,
                hour_meter: u.hour_meter === '' ? null : u.hour_meter,
                fuel: u.fuel,
                clean: u.clean,
                notes: u.notes,
                photos: u.photos.map((p) => ({
                    file: p.file,
                    label: p.label,
                    new_damage: p.new_damage,
                })),
                damage_description: isReturn ? u.damage_description : '',
                damage_amount: isReturn ? u.damage_amount : '',
                damage_covered: u.damage_covered,
            })),
        }));
        form.post(window.location.pathname, { forceFormData: true });
    }

    if (!item || !unit) {
        return null;
    }

    const errors = form.errors as Record<string, string>;
    const errorList = Object.values(errors);

    return (
        <>
            <Head
                title={`${isReturn ? 'Return' : 'Check-out'} inspection ${rental.number}`}
            />
            <div className="mx-auto flex min-h-svh max-w-[520px] flex-col bg-background">
                <header className="flex flex-col gap-0.5 bg-[#1c1917] px-5 py-4 text-[#fafaf9]">
                    <Link
                        href={RentalController.show(rental.id)}
                        className="flex min-h-11 items-center text-[15px] text-[#d6d3d1]"
                    >
                        ‹ {rental.number}
                    </Link>
                    <div className="text-xs tracking-[0.06em] text-[#fdba74] uppercase">
                        {isReturn
                            ? 'Return inspection'
                            : 'Check-out inspection'}
                    </div>
                    <h1 className="text-[21px] font-semibold">
                        {item.description} · {item.serial}
                    </h1>
                    <div className="text-sm text-[#d6d3d1]">
                        {rental.organization?.name ??
                            rental.customer?.name ??
                            'Walk-in'}{' '}
                        · {rental.number}
                    </div>
                    {rental.items.length > 1 && (
                        <div
                            className="mt-2 flex gap-1.5"
                            role="tablist"
                            aria-label="Units"
                        >
                            {rental.items.map((it, i) => (
                                <button
                                    key={it.id}
                                    type="button"
                                    role="tab"
                                    aria-selected={i === current}
                                    onClick={() => setCurrent(i)}
                                    className={cn(
                                        'min-h-10 rounded-lg px-3 text-sm',
                                        i === current
                                            ? 'bg-[#fafaf9] font-semibold text-[#1c1917]'
                                            : 'bg-[#292524] text-[#d6d3d1]',
                                    )}
                                >
                                    {it.serial}
                                </button>
                            ))}
                        </div>
                    )}
                </header>

                <div className="flex flex-1 flex-col gap-3.5 overflow-auto p-4">
                    <section className="flex flex-col gap-2.5 rounded-xl bg-card p-3.5">
                        <h2 className="text-[15px] font-semibold">
                            Photos{' '}
                            {isReturn && before && (
                                <span className="font-normal text-muted-foreground">
                                    · compared with check-out (
                                    {before.photo_count})
                                </span>
                            )}
                        </h2>
                        <div className="grid grid-cols-2 gap-2">
                            {unit.photos.map((photo, i) => (
                                <div
                                    key={photo.preview}
                                    className={cn(
                                        'relative aspect-[4/3] overflow-hidden rounded-lg bg-[#d6d3d1]',
                                        photo.new_damage &&
                                            'outline-[3px] outline-[#d97706] outline-solid',
                                    )}
                                >
                                    <img
                                        src={photo.preview}
                                        alt={photo.label}
                                        className="size-full object-cover"
                                    />
                                    <button
                                        type="button"
                                        aria-label={`Remove ${photo.label} photo`}
                                        className="absolute top-1 right-1 flex size-8 items-center justify-center rounded-full bg-black/60 text-white"
                                        onClick={() =>
                                            update({
                                                photos: unit.photos.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            })
                                        }
                                    >
                                        <X className="size-4" />
                                    </button>
                                    <div className="absolute inset-x-0 bottom-0 flex items-center gap-1 bg-black/55 p-1">
                                        <select
                                            aria-label="Photo label"
                                            value={photo.label}
                                            onChange={(e) =>
                                                update({
                                                    photos: unit.photos.map(
                                                        (p, j) =>
                                                            j === i
                                                                ? {
                                                                      ...p,
                                                                      label: e
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : p,
                                                    ),
                                                })
                                            }
                                            className="min-w-0 flex-1 bg-transparent text-xs font-semibold text-white"
                                        >
                                            {[
                                                ...new Set([
                                                    photo.label,
                                                    ...photoLabels,
                                                ]),
                                            ].map((l) => (
                                                <option
                                                    key={l}
                                                    value={l}
                                                    className="text-black"
                                                >
                                                    {l}
                                                </option>
                                            ))}
                                        </select>
                                        {isReturn && (
                                            <label className="flex items-center gap-1 text-[11px] text-white">
                                                <input
                                                    type="checkbox"
                                                    checked={photo.new_damage}
                                                    onChange={(e) =>
                                                        update({
                                                            photos: unit.photos.map(
                                                                (p, j) =>
                                                                    j === i
                                                                        ? {
                                                                              ...p,
                                                                              new_damage:
                                                                                  e
                                                                                      .target
                                                                                      .checked,
                                                                          }
                                                                        : p,
                                                            ),
                                                        })
                                                    }
                                                />
                                                New mark
                                            </label>
                                        )}
                                    </div>
                                </div>
                            ))}
                            <label className="flex aspect-[4/3] cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-[#a8a29e] bg-[#fafaf9] text-sm text-subtle">
                                <Camera className="size-5" aria-hidden="true" />
                                Add photo
                                <input
                                    type="file"
                                    accept="image/*"
                                    capture="environment"
                                    multiple
                                    className="sr-only"
                                    onChange={(e) => {
                                        addPhotos(e.target.files);
                                        e.target.value = '';
                                    }}
                                />
                            </label>
                        </div>
                    </section>

                    <section className="flex flex-col gap-3 rounded-xl bg-card p-3.5">
                        <h2 className="text-[15px] font-semibold">Readings</h2>
                        <label className="flex items-center justify-between gap-2.5">
                            <span>
                                Hour meter{' '}
                                {isReturn && before?.hour_meter && (
                                    <span className="text-[13px] text-muted-foreground">
                                        (out {Number(before.hour_meter)})
                                    </span>
                                )}
                            </span>
                            <input
                                inputMode="decimal"
                                value={unit.hour_meter}
                                onChange={(e) =>
                                    update({ hour_meter: e.target.value })
                                }
                                className="figure h-11 w-24 rounded-lg border border-input px-2.5 text-right text-base"
                            />
                        </label>
                        <div className="flex items-center justify-between">
                            <span>Fuel</span>
                            <ToggleButtons<Fuel>
                                label="Fuel level"
                                size="sm"
                                value={unit.fuel}
                                onChange={(fuel) => update({ fuel })}
                                options={[
                                    { value: 'full', label: 'Full' },
                                    { value: 'half', label: '½' },
                                    { value: 'low', label: 'Low' },
                                ]}
                            />
                        </div>
                        <div className="flex items-center justify-between">
                            <span>Clean</span>
                            <ToggleButtons<'yes' | 'no'>
                                label="Clean"
                                size="sm"
                                value={unit.clean ? 'yes' : 'no'}
                                onChange={(v) => update({ clean: v === 'yes' })}
                                options={[
                                    { value: 'yes', label: 'Yes' },
                                    { value: 'no', label: 'No' },
                                ]}
                            />
                        </div>
                        <input
                            value={unit.notes}
                            onChange={(e) => update({ notes: e.target.value })}
                            placeholder="Condition notes"
                            aria-label="Condition notes"
                            className="h-11 rounded-lg border border-input px-3"
                        />
                    </section>

                    {isReturn && (
                        <section className="flex flex-col gap-2 rounded-xl bg-card p-3.5">
                            <h2 className="text-[15px] font-semibold">
                                Charges to add
                            </h2>
                            {fuelCharge > 0 && (
                                <div className="flex justify-between">
                                    <span>Refuel to full</span>
                                    <span className="figure">
                                        {formatMoney(fuelCharge)}
                                    </span>
                                </div>
                            )}
                            {cleaningCharge > 0 && (
                                <div className="flex justify-between">
                                    <span>Cleaning</span>
                                    <span className="figure">
                                        {formatMoney(cleaningCharge)}
                                    </span>
                                </div>
                            )}
                            <input
                                value={unit.damage_description}
                                onChange={(e) =>
                                    update({
                                        damage_description: e.target.value,
                                    })
                                }
                                placeholder="Damage, e.g. base plate mark"
                                aria-label="Damage"
                                className="h-11 rounded-lg border border-input px-3"
                            />
                            {unit.damage_description !== '' && (
                                <div className="flex items-center justify-between gap-2">
                                    {rental.damage_waiver ? (
                                        <label className="flex min-h-11 items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                className="size-5 accent-primary"
                                                checked={unit.damage_covered}
                                                onChange={(e) =>
                                                    update({
                                                        damage_covered:
                                                            e.target.checked,
                                                    })
                                                }
                                            />
                                            Covered by waiver
                                        </label>
                                    ) : (
                                        <span className="text-sm text-muted-foreground">
                                            No waiver: customer pays
                                        </span>
                                    )}
                                    <input
                                        inputMode="decimal"
                                        value={unit.damage_amount}
                                        onChange={(e) =>
                                            update({
                                                damage_amount: e.target.value,
                                            })
                                        }
                                        placeholder="0.00"
                                        aria-label="Damage amount"
                                        className="figure h-11 w-28 rounded-lg border border-input px-2.5 text-right"
                                    />
                                </div>
                            )}
                            {late === 0 ? (
                                <div className="text-[13px] text-success">
                                    Returned on time.
                                </div>
                            ) : (
                                <div className="text-[13px] text-danger">
                                    Returned late: about {late} extra{' '}
                                    {late === 1 ? 'day' : 'days'}, charged at
                                    the best rate.
                                </div>
                            )}
                            {serviceDueNow && (
                                <div className="rounded-lg bg-warning-soft p-2.5 text-[13px] text-warning">
                                    {hoursNow} hrs: service is due.{' '}
                                    {item.serial} will be locked out of new
                                    rentals until the service is logged.
                                </div>
                            )}
                        </section>
                    )}

                    {!isReturn &&
                        rental.deposit_cents > 0 &&
                        !rental.customer?.card && (
                            <section className="flex flex-col gap-2 rounded-xl bg-card p-3.5">
                                <h2 className="text-[15px] font-semibold">
                                    Deposit, {formatMoney(rental.deposit_cents)}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    No card on file to hold it on. How was it
                                    taken?
                                </p>
                                <ToggleButtons
                                    label="Deposit taken by"
                                    value={form.data.deposit_tender}
                                    onChange={(v) =>
                                        form.setData('deposit_tender', v)
                                    }
                                    options={[
                                        {
                                            value: 'card_terminal',
                                            label: 'Terminal',
                                        },
                                        { value: 'cash', label: 'Cash' },
                                        { value: 'cheque', label: 'Cheque' },
                                    ]}
                                />
                            </section>
                        )}

                    {errorList.length > 0 && (
                        <div
                            role="alert"
                            className="rounded-lg bg-danger-soft p-3 text-sm text-danger"
                        >
                            {errorList.map((e) => (
                                <div key={e}>{e}</div>
                            ))}
                        </div>
                    )}
                </div>

                <footer className="sticky bottom-0 flex flex-col gap-2 border-t border-border bg-card p-4">
                    {rental.items.length > 1 &&
                    current < rental.items.length - 1 ? (
                        <button
                            type="button"
                            className="h-14 rounded-[10px] bg-[#1c1917] text-[17px] font-bold text-white"
                            onClick={() => setCurrent(current + 1)}
                        >
                            Next unit: {rental.items[current + 1].serial}
                        </button>
                    ) : (
                        <button
                            type="button"
                            disabled={form.processing}
                            className="h-14 rounded-[10px] bg-primary text-[17px] font-bold text-white hover:bg-[#9a3412] disabled:opacity-60"
                            onClick={submit}
                        >
                            {isReturn
                                ? rental.deposit_status === 'held'
                                    ? 'Check in and release deposit'
                                    : 'Check in'
                                : rental.deposit_cents > 0
                                  ? 'Check out and hold deposit'
                                  : 'Check out'}
                        </button>
                    )}
                    <div className="text-center text-xs text-muted-foreground">
                        {isReturn
                            ? 'The customer gets a receipt by email or text.'
                            : 'Photos are saved to the contract for the return comparison.'}
                    </div>
                </footer>
            </div>
        </>
    );
}
