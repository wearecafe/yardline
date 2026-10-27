import { router, useForm } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import type { FormEvent } from 'react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import { StepActions, StepHeader, StepSection } from '@/components/setup/step';
import { Field, NativeSelect } from '@/components/yard/field';
import { Notice } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    people: { id: number; name: string; email: string | null; roles: string }[];
    roles: { value: string; label: string; description: string }[];
    canInvite: boolean;
};

export default function SetupTeam({ people, roles, canInvite }: Props) {
    const form = useForm({ name: '', email: '', role: 'sales' });
    const role = roles.find((r) => r.value === form.data.role);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(SetupController.invite.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset('name', 'email'),
        });
    }

    return (
        <>
            <StepHeader description="Give each person a role for the job they do: the counter, the yard, dispatch or the office. They get an email to choose a password." />

            <div className="grid gap-8">
                {people.length > 0 && (
                    <StepSection title="Invited">
                        <ul className="divide-y divide-border rounded-[10px] border border-border bg-card">
                            {people.map((person) => (
                                <li
                                    key={person.id}
                                    className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 px-4 py-3"
                                >
                                    <span className="font-medium">
                                        {person.name}
                                        {person.email && (
                                            <span className="ml-2 text-sm font-normal text-muted-foreground">
                                                {person.email}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-sm text-subtle">
                                        {person.roles}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </StepSection>
                )}

                {canInvite ? (
                    <StepSection
                        title={
                            people.length > 0
                                ? 'Invite someone else'
                                : 'Invite someone'
                        }
                    >
                        <form
                            onSubmit={submit}
                            className="grid gap-4 rounded-[10px] border border-border bg-card p-5"
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Name"
                                    htmlFor="name"
                                    error={form.errors.name}
                                >
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        autoComplete="off"
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Email"
                                    htmlFor="email"
                                    error={form.errors.email}
                                >
                                    <Input
                                        id="email"
                                        type="email"
                                        value={form.data.email}
                                        autoComplete="off"
                                        onChange={(e) =>
                                            form.setData(
                                                'email',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Field>
                            </div>
                            <Field
                                label="Role"
                                htmlFor="role"
                                error={form.errors.role}
                                hint={role?.description}
                            >
                                <NativeSelect
                                    id="role"
                                    value={form.data.role}
                                    onChange={(e) =>
                                        form.setData('role', e.target.value)
                                    }
                                >
                                    {roles.map((r) => (
                                        <option key={r.value} value={r.value}>
                                            {r.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={form.processing}
                                >
                                    <UserPlus />
                                    Send invite
                                </Button>
                            </div>
                        </form>
                        <p className="text-sm text-muted-foreground">
                            Drivers are added under Settings → Drivers once your
                            trucks are in, and yard hands who only clock in
                            under Team → Employees. Fine-tune roles, yards and
                            permissions any time under Settings → Users.
                        </p>
                    </StepSection>
                ) : (
                    <Notice>
                        Only people who can manage users invite the team. Ask
                        the owner to add people under Settings → Users.
                    </Notice>
                )}
            </div>

            <StepActions
                primary="Continue"
                skip={false}
                onPrimary={() =>
                    router.post(SetupController.next.url({ step: 'team' }))
                }
            />
        </>
    );
}
