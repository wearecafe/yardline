import { Head, router, useForm } from '@inertiajs/react';
import UserAccountsController from '@/actions/App/Http/Controllers/Settings/UserAccountsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import { Field, NativeSelect } from '@/components/yard/field';

type Role = 'owner' | 'admin' | 'staff';

type UserAccount = {
    id: number;
    name: string;
    email: string;
    role: Role;
    joined: boolean;
    is_you: boolean;
    manageable: boolean;
};

type Props = {
    users: UserAccount[];
    roles: { value: Role; label: string }[];
};

const roleLabels: Record<Role, string> = {
    owner: 'Owner',
    admin: 'Admin',
    staff: 'Staff',
};

function AddUser({ roles }: Pick<Props, 'roles'>) {
    const form = useForm({ name: '', email: '', role: 'staff' as Role });

    return (
        <form
            className="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-6 sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(UserAccountsController.store.url(), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Field
                label="Name"
                htmlFor="user-name"
                error={form.errors.name}
                className="sm:col-span-2"
            >
                <Input
                    id="user-name"
                    required
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder="Jess Palmer"
                />
            </Field>
            <Field
                label="Email"
                htmlFor="user-email"
                error={form.errors.email}
                className="sm:col-span-2"
            >
                <Input
                    id="user-email"
                    type="email"
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
            </Field>
            <Field label="Role" htmlFor="user-role" error={form.errors.role}>
                <NativeSelect
                    id="user-role"
                    value={form.data.role}
                    onChange={(e) =>
                        form.setData('role', e.target.value as Role)
                    }
                >
                    {roles.map((r) => (
                        <option key={r.value} value={r.value}>
                            {r.label}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Button type="submit" disabled={form.processing}>
                Add user
            </Button>
        </form>
    );
}

export default function UsersSettings({ users, roles }: Props) {
    return (
        <>
            <Head title="Users" />
            <h1 className="sr-only">Users</h1>

            <div className="space-y-10">
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Users"
                        description="People who sign in to the yard. Staff work orders, stock and rentals. Admins can also change business settings, credit terms and line prices, and override credit limits. Drivers are added under Drivers."
                    />

                    <ul className="divide-y divide-border rounded-lg border border-border">
                        {users.map((u) => (
                            <li
                                key={u.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-3"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2 font-medium">
                                        {u.name}
                                        {u.is_you && (
                                            <span className="text-sm font-normal text-muted-foreground">
                                                (you)
                                            </span>
                                        )}
                                        {!u.joined && (
                                            <Chip tone="warning">Invited</Chip>
                                        )}
                                    </div>
                                    <div className="truncate text-sm text-muted-foreground">
                                        {u.email}
                                    </div>
                                </div>

                                {u.manageable ? (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <NativeSelect
                                            aria-label={`Role for ${u.name}`}
                                            className="h-8 w-auto"
                                            value={u.role}
                                            onChange={(e) =>
                                                router.patch(
                                                    UserAccountsController.update.url(
                                                        u.id,
                                                    ),
                                                    { role: e.target.value },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {roles.map((r) => (
                                                <option
                                                    key={r.value}
                                                    value={r.value}
                                                >
                                                    {r.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        {!u.joined && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        UserAccountsController.resend.url(
                                                            u.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Send link again
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                if (
                                                    confirm(
                                                        `Remove ${u.name}? They won't be able to sign in.`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        UserAccountsController.destroy.url(
                                                            u.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                ) : (
                                    <Chip tone="neutral">
                                        {roleLabels[u.role]}
                                    </Chip>
                                )}
                            </li>
                        ))}
                    </ul>

                    <AddUser roles={roles} />
                </section>
            </div>
        </>
    );
}
