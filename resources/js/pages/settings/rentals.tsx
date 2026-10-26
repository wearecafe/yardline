import { Head, Link, useForm } from '@inertiajs/react';
import DeliverySettingsController from '@/actions/App/Http/Controllers/Settings/DeliverySettingsController';
import RentalSettingsController from '@/actions/App/Http/Controllers/Settings/RentalSettingsController';
import Heading from '@/components/heading';
import { Checkbox, Field } from '@/components/yard/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Settings = {
    damage_waiver_percent: number;
    free_sunday_with_saturday: boolean;
    late_grace_minutes: number;
    late_fee_policy: string;
    terms: string;
    reminder_hours_before_return: number;
    opening_time: string;
    closing_time: string;
};

export default function RentalSettingsPage({
    settings,
    bookingUrl,
}: {
    settings: Settings;
    bookingUrl: string | null;
}) {
    const form = useForm({
        ...settings,
        damage_waiver_percent: String(settings.damage_waiver_percent),
        late_grace_minutes: String(settings.late_grace_minutes),
        reminder_hours_before_return: String(
            settings.reminder_hours_before_return,
        ),
    });

    return (
        <>
            <Head title="Rental settings" />
            <h1 className="sr-only">Rental settings</h1>

            <div className="space-y-10">
                <form
                    className="space-y-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(RentalSettingsController.update.url(), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Heading
                        variant="small"
                        title="Rental policy"
                        description="Pricing rules, hours and the terms customers sign."
                    />

                    {bookingUrl && (
                        <p className="text-sm text-muted-foreground">
                            Your public booking page is{' '}
                            <a
                                href={bookingUrl}
                                className="font-medium text-foreground underline underline-offset-4"
                                target="_blank"
                                rel="noreferrer"
                            >
                                {bookingUrl}
                            </a>
                            . Equipment shows there when it's marked bookable
                            online.
                        </p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Damage waiver (%)"
                            htmlFor="waiver"
                            error={form.errors.damage_waiver_percent}
                            hint="Of the rental charge. Customers can decline it."
                        >
                            <Input
                                id="waiver"
                                inputMode="decimal"
                                value={form.data.damage_waiver_percent}
                                onChange={(e) =>
                                    form.setData(
                                        'damage_waiver_percent',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Late grace (minutes)"
                            htmlFor="grace"
                            error={form.errors.late_grace_minutes}
                        >
                            <Input
                                id="grace"
                                inputMode="numeric"
                                value={form.data.late_grace_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'late_grace_minutes',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Opens"
                            htmlFor="opening"
                            error={form.errors.opening_time}
                        >
                            <Input
                                id="opening"
                                type="time"
                                value={form.data.opening_time}
                                onChange={(e) =>
                                    form.setData('opening_time', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Closes"
                            htmlFor="closing"
                            error={form.errors.closing_time}
                        >
                            <Input
                                id="closing"
                                type="time"
                                value={form.data.closing_time}
                                onChange={(e) =>
                                    form.setData('closing_time', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Return reminder (hours before)"
                            htmlFor="reminder"
                            error={form.errors.reminder_hours_before_return}
                        >
                            <Input
                                id="reminder"
                                inputMode="numeric"
                                value={form.data.reminder_hours_before_return}
                                onChange={(e) =>
                                    form.setData(
                                        'reminder_hours_before_return',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>

                    <Checkbox
                        id="free_sunday"
                        checked={form.data.free_sunday_with_saturday}
                        onChange={(checked) =>
                            form.setData('free_sunday_with_saturday', checked)
                        }
                        label="Sunday is free when picked up Saturday and returned Monday morning"
                    />

                    <Field
                        label="Late fee policy"
                        htmlFor="late_policy"
                        error={form.errors.late_fee_policy}
                        hint="Shown on the agreement."
                    >
                        <Input
                            id="late_policy"
                            value={form.data.late_fee_policy}
                            onChange={(e) =>
                                form.setData('late_fee_policy', e.target.value)
                            }
                        />
                    </Field>

                    <Field
                        label="Rental terms"
                        htmlFor="terms"
                        error={form.errors.terms}
                        hint="Customers sign these on the agreement. Have them reviewed by your lawyer."
                    >
                        <textarea
                            id="terms"
                            rows={12}
                            value={form.data.terms}
                            onChange={(e) =>
                                form.setData('terms', e.target.value)
                            }
                            className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm"
                        />
                    </Field>

                    <Button type="submit" disabled={form.processing}>
                        Save rental settings
                    </Button>
                </form>

                <p className="text-sm text-muted-foreground">
                    Delivery zones and fees for rentals and material are under{' '}
                    <Link
                        href={DeliverySettingsController.edit.url()}
                        className="font-medium text-foreground underline underline-offset-4"
                    >
                        Delivery
                    </Link>
                    .
                </p>
            </div>
        </>
    );
}
