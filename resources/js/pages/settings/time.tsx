import { Head, useForm } from '@inertiajs/react';
import TimeSettingsController from '@/actions/App/Http/Controllers/Settings/TimeSettingsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import { Notice } from '@/components/yard/page-header';

type Settings = {
    enabled: boolean;
    province: string;
    weekly_overtime_hours: number;
    meal_after_hours: number;
    meal_minutes: number;
    long_shift_hours: number;
    daily_rest_hours: number;
    check_daily_rest: boolean;
    rounding_minutes: number;
    auto_meal_deduction: boolean;
    frequency: string;
    anchor: string;
    week_starts_on: number;
    photo: string;
    geofence_m: number;
    adp_company_code: string;
    adp_batch_id: string;
};

type Option = { value: string; label: string };

type Yard = {
    id: number;
    name: string;
    latitude: string | null;
    longitude: string | null;
    geofence_m: number | null;
};

type Props = {
    settings: Settings;
    provinces: Option[];
    defaults: Record<string, Settings>;
    frequencies: Option[];
    photoModes: Option[];
    weekdays: Option[];
    yards: Yard[];
    tracked: number;
};

/**
 * Settings → Time: turn time and attendance on, the province's rules,
 * pay periods, the time clock, where each yard is, and ADP.
 */
export default function TimeSettings({
    settings,
    provinces,
    defaults,
    frequencies,
    photoModes,
    weekdays,
    yards,
    tracked,
}: Props) {
    const form = useForm({
        ...settings,
        yards: yards.map((y) => ({
            id: y.id,
            name: y.name,
            latitude: y.latitude ?? '',
            longitude: y.longitude ?? '',
            geofence_m: y.geofence_m ? String(y.geofence_m) : '',
        })),
    });
    const err = form.errors as Record<string, string | undefined>;

    function applyProvince(province: string) {
        const d = defaults[province];

        if (!d) {
            return;
        }

        form.setData((data) => ({
            ...data,
            province,
            weekly_overtime_hours: d.weekly_overtime_hours,
            meal_after_hours: d.meal_after_hours,
            meal_minutes: d.meal_minutes,
            daily_rest_hours: d.daily_rest_hours,
            check_daily_rest: d.check_daily_rest,
        }));
    }

    function setYard(
        index: number,
        key: 'latitude' | 'longitude' | 'geofence_m',
        value: string,
    ) {
        form.setData(
            'yards',
            form.data.yards.map((y, i) =>
                i === index ? { ...y, [key]: value } : y,
            ),
        );
    }

    return (
        <>
            <Head title="Time settings" />
            <form
                className="space-y-10"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        yards: data.yards.map((y) => ({
                            id: y.id,
                            latitude: y.latitude === '' ? null : y.latitude,
                            longitude: y.longitude === '' ? null : y.longitude,
                            geofence_m:
                                y.geofence_m === '' ? null : y.geofence_m,
                        })),
                    }));
                    form.put(TimeSettingsController.update.url(), {
                        preserveScroll: true,
                    });
                }}
            >
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Time and attendance"
                        description="Clock in from the counter tablet, a phone or the driver app; review timesheets and send hours to payroll. A paid add-on, billed per person who clocks in."
                    />
                    <Checkbox
                        id="enabled"
                        label="Track time for this business"
                        checked={form.data.enabled}
                        onChange={(v) => form.setData('enabled', v)}
                        hint={
                            settings.enabled
                                ? `${tracked} ${tracked === 1 ? 'person clocks' : 'people clock'} in.`
                                : 'Turning it on sets up everyone who signs in; you add PINs and yard hands after.'
                        }
                    />
                </div>

                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Overtime and breaks"
                        description="One set of rules for everyone, starting from your province’s standards. Check them against the current rules and any averaging agreement you have."
                    />
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field
                            label="Province"
                            htmlFor="province"
                            error={err.province}
                        >
                            <NativeSelect
                                id="province"
                                value={form.data.province}
                                onChange={(e) => applyProvince(e.target.value)}
                            >
                                {provinces.map((p) => (
                                    <option key={p.value} value={p.value}>
                                        {p.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            label="Overtime after (hours a week)"
                            htmlFor="weekly_overtime_hours"
                            error={err.weekly_overtime_hours}
                            hint="At time and a half."
                        >
                            <Input
                                id="weekly_overtime_hours"
                                inputMode="numeric"
                                value={String(form.data.weekly_overtime_hours)}
                                onChange={(e) =>
                                    form.setData(
                                        'weekly_overtime_hours',
                                        Number(e.target.value) || 0,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Workweek starts"
                            htmlFor="week_starts_on"
                            error={err.week_starts_on}
                        >
                            <NativeSelect
                                id="week_starts_on"
                                value={String(form.data.week_starts_on)}
                                onChange={(e) =>
                                    form.setData(
                                        'week_starts_on',
                                        Number(e.target.value),
                                    )
                                }
                            >
                                {weekdays.map((d) => (
                                    <option key={d.value} value={d.value}>
                                        {d.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            label="Meal break due after (hours)"
                            htmlFor="meal_after_hours"
                            error={err.meal_after_hours}
                        >
                            <Input
                                id="meal_after_hours"
                                inputMode="numeric"
                                value={String(form.data.meal_after_hours)}
                                onChange={(e) =>
                                    form.setData(
                                        'meal_after_hours',
                                        Number(e.target.value) || 0,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Meal break (minutes, unpaid)"
                            htmlFor="meal_minutes"
                            error={err.meal_minutes}
                        >
                            <Input
                                id="meal_minutes"
                                inputMode="numeric"
                                value={String(form.data.meal_minutes)}
                                onChange={(e) =>
                                    form.setData(
                                        'meal_minutes',
                                        Number(e.target.value) || 0,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Flag shifts longer than (hours)"
                            htmlFor="long_shift_hours"
                            error={err.long_shift_hours}
                        >
                            <Input
                                id="long_shift_hours"
                                inputMode="numeric"
                                value={String(form.data.long_shift_hours)}
                                onChange={(e) =>
                                    form.setData(
                                        'long_shift_hours',
                                        Number(e.target.value) || 0,
                                    )
                                }
                            />
                        </Field>
                    </div>
                    <div className="grid gap-2">
                        <Checkbox
                            id="check_daily_rest"
                            label={`Flag less than ${form.data.daily_rest_hours} hours off between shifts`}
                            checked={form.data.check_daily_rest}
                            onChange={(v) =>
                                form.setData('check_daily_rest', v)
                            }
                        />
                        <Checkbox
                            id="auto_meal_deduction"
                            label="Take the meal break off automatically when none was punched"
                            hint="Off by default: it’s better for people to punch their breaks."
                            checked={form.data.auto_meal_deduction}
                            onChange={(v) =>
                                form.setData('auto_meal_deduction', v)
                            }
                        />
                    </div>
                    <Field
                        label="Round punches"
                        htmlFor="rounding_minutes"
                        error={err.rounding_minutes}
                        hint="Rounds to the nearest step, up or down evenly, so it never shortchanges anyone."
                        className="max-w-xs"
                    >
                        <NativeSelect
                            id="rounding_minutes"
                            value={String(form.data.rounding_minutes)}
                            onChange={(e) =>
                                form.setData(
                                    'rounding_minutes',
                                    Number(e.target.value),
                                )
                            }
                        >
                            <option value="0">Don’t round</option>
                            {[5, 6, 10, 15].map((m) => (
                                <option key={m} value={m}>
                                    Nearest {m} minutes
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                </div>

                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Pay periods"
                        description="Changing this only shapes periods nobody has worked yet."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Paid"
                            htmlFor="frequency"
                            error={err.frequency}
                        >
                            <NativeSelect
                                id="frequency"
                                value={form.data.frequency}
                                onChange={(e) =>
                                    form.setData('frequency', e.target.value)
                                }
                            >
                                {frequencies.map((f) => (
                                    <option key={f.value} value={f.value}>
                                        {f.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        {form.data.frequency !== 'semimonthly' && (
                            <Field
                                label="A period starts on"
                                htmlFor="anchor"
                                error={err.anchor}
                                hint="Any first day of a pay period."
                            >
                                <Input
                                    id="anchor"
                                    type="date"
                                    value={form.data.anchor}
                                    onChange={(e) =>
                                        form.setData('anchor', e.target.value)
                                    }
                                />
                            </Field>
                        )}
                    </div>
                </div>

                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Time clock and phones"
                        description="Phone punches are checked against each yard. A punch away from every yard is still recorded, just flagged."
                    />
                    <Field
                        label="Photo on time clock punches"
                        htmlFor="photo"
                        error={err.photo}
                        hint="“Selected people” is set on each person under Team → Employees."
                        className="max-w-xs"
                    >
                        <NativeSelect
                            id="photo"
                            value={form.data.photo}
                            onChange={(e) =>
                                form.setData('photo', e.target.value)
                            }
                        >
                            {photoModes.map((m) => (
                                <option key={m.value} value={m.value}>
                                    {m.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field
                        label="Yard radius (metres)"
                        htmlFor="geofence_m"
                        error={err.geofence_m}
                        className="max-w-xs"
                    >
                        <Input
                            id="geofence_m"
                            inputMode="numeric"
                            value={String(form.data.geofence_m)}
                            onChange={(e) =>
                                form.setData(
                                    'geofence_m',
                                    Number(e.target.value) || 0,
                                )
                            }
                        />
                    </Field>
                    <div className="grid gap-3">
                        {form.data.yards.map((y, i) => (
                            <div
                                key={y.id}
                                className="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-[1fr_1fr_1fr_8rem] sm:items-end"
                            >
                                <div className="font-medium sm:pb-2">
                                    {y.name}
                                </div>
                                <Field
                                    label="Latitude"
                                    htmlFor={`lat-${y.id}`}
                                    error={err[`yards.${i}.latitude`]}
                                >
                                    <Input
                                        id={`lat-${y.id}`}
                                        inputMode="decimal"
                                        placeholder="43.3335"
                                        value={y.latitude}
                                        onChange={(e) =>
                                            setYard(
                                                i,
                                                'latitude',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Longitude"
                                    htmlFor={`lng-${y.id}`}
                                    error={err[`yards.${i}.longitude`]}
                                >
                                    <Input
                                        id={`lng-${y.id}`}
                                        inputMode="decimal"
                                        placeholder="-79.8795"
                                        value={y.longitude}
                                        onChange={(e) =>
                                            setYard(
                                                i,
                                                'longitude',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Radius (m)"
                                    htmlFor={`radius-${y.id}`}
                                    error={err[`yards.${i}.geofence_m`]}
                                >
                                    <Input
                                        id={`radius-${y.id}`}
                                        inputMode="numeric"
                                        placeholder={String(
                                            form.data.geofence_m,
                                        )}
                                        value={y.geofence_m}
                                        onChange={(e) =>
                                            setYard(
                                                i,
                                                'geofence_m',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </div>
                        ))}
                    </div>
                    {form.data.yards.every((y) => y.latitude === '') && (
                        <Notice>
                            No yard has a position yet, so phone punches aren’t
                            checked. Copy the yard’s coordinates from a map
                            (right-click the gate in Google Maps).
                        </Notice>
                    )}
                </div>

                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="ADP"
                        description="For the ADP pay data import. Each person’s file number is their payroll ID."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Company code"
                            htmlFor="adp_company_code"
                            error={err.adp_company_code}
                        >
                            <Input
                                id="adp_company_code"
                                value={form.data.adp_company_code}
                                onChange={(e) =>
                                    form.setData(
                                        'adp_company_code',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Batch prefix"
                            htmlFor="adp_batch_id"
                            error={err.adp_batch_id}
                            hint="The period’s end date is added to it."
                        >
                            <Input
                                id="adp_batch_id"
                                value={form.data.adp_batch_id}
                                onChange={(e) =>
                                    form.setData('adp_batch_id', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </div>

                <Button type="submit" disabled={form.processing}>
                    Save time settings
                </Button>
            </form>
        </>
    );
}
