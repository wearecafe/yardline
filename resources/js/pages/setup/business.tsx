import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import { StepActions, StepHeader } from '@/components/setup/step';
import { Field, NativeSelect } from '@/components/yard/field';
import { Input } from '@/components/ui/input';

type Props = {
    business: {
        name: string;
        timezone: string;
        reply_to_email: string | null;
        tax_numbers: string;
    };
    guessTimezone: boolean;
    timezones: string[];
};

/** The browser's time zone, if it's one we offer. */
function browserTimezone(timezones: string[]): string | null {
    try {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        return timezones.includes(zone) ? zone : null;
    } catch {
        return null;
    }
}

export default function SetupBusiness({
    business,
    guessTimezone,
    timezones,
}: Props) {
    const form = useForm({
        name: business.name,
        timezone:
            (guessTimezone ? browserTimezone(timezones) : null) ??
            business.timezone,
        reply_to_email: business.reply_to_email ?? '',
        tax_numbers: business.tax_numbers,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(SetupController.business.url());
    }

    return (
        <>
            <StepHeader description="Two quick steps get YardRunner ready for your first sale: your business, then your yards. Everything after that can wait." />
            <form onSubmit={submit} className="grid gap-6">
                <Field
                    label="Business name"
                    htmlFor="name"
                    error={form.errors.name}
                    hint="As customers know you. It heads quotes, invoices and emails."
                >
                    <Input
                        id="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        autoComplete="organization"
                        required
                    />
                </Field>
                <Field
                    label="Email customers reply to"
                    htmlFor="reply_to_email"
                    error={form.errors.reply_to_email}
                    hint="Quotes, statements and rental emails go out under your business name. Replies come here."
                >
                    <Input
                        id="reply_to_email"
                        type="email"
                        value={form.data.reply_to_email}
                        placeholder="sales@youryard.ca"
                        onChange={(e) =>
                            form.setData('reply_to_email', e.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Time zone"
                    htmlFor="timezone"
                    error={form.errors.timezone}
                    hint="Sets when your day starts for reports, dispatch and timesheets."
                >
                    <NativeSelect
                        id="timezone"
                        value={form.data.timezone}
                        onChange={(e) =>
                            form.setData('timezone', e.target.value)
                        }
                    >
                        {timezones.map((zone) => (
                            <option key={zone} value={zone}>
                                {zone
                                    .replace('America/', '')
                                    .replace(/_/g, ' ')}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label={
                        <>
                            Tax numbers{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </>
                    }
                    htmlFor="tax_numbers"
                    error={form.errors.tax_numbers}
                    hint="Printed on invoices and receipts, e.g. HST 123456789 RT0001."
                >
                    <Input
                        id="tax_numbers"
                        value={form.data.tax_numbers}
                        placeholder="HST 123456789 RT0001"
                        onChange={(e) =>
                            form.setData('tax_numbers', e.target.value)
                        }
                    />
                </Field>
                <StepActions processing={form.processing} />
            </form>
        </>
    );
}
