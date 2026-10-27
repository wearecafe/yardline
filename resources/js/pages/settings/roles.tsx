import { Head, useForm } from '@inertiajs/react';
import RoleSettingsController from '@/actions/App/Http/Controllers/Settings/RoleSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Role = {
    value: string;
    label: string;
    description: string;
    editable: boolean;
    permissions: string[];
    refund_limit_cents: number | null;
};

type PermissionOption = {
    value: string;
    label: string;
    description: string;
    requires: string | null;
    editable: boolean;
};

type Props = {
    roles: Role[];
    permissions: PermissionOption[];
};

function dollars(cents: number | null): string {
    return cents === null ? '' : (cents / 100).toFixed(2);
}

/**
 * Settings → Roles: what each role gives by default, and the most each
 * role can refund before someone with Refund above limit approves it.
 * Changes reach everyone holding the role, unless a permission was
 * granted or removed on the person under Users.
 */
export default function RoleSettings({ roles, permissions }: Props) {
    const editable = roles.filter((r) => r.editable);
    const form = useForm({
        role_permissions: Object.fromEntries(
            editable.map((r) => [r.value, r.permissions]),
        ) as Record<string, string[]>,
        refund_limits: Object.fromEntries(
            editable.map((r) => [r.value, dollars(r.refund_limit_cents)]),
        ) as Record<string, string>,
    });
    const errors = form.errors as Record<string, string | undefined>;

    function toggle(role: string, permission: PermissionOption, on: boolean) {
        form.setData((data) => {
            let list = data.role_permissions[role].filter(
                (p) => p !== permission.value,
            );

            if (on) {
                list = [...list, permission.value];

                // A permission that needs another brings it along.
                if (
                    permission.requires &&
                    !list.includes(permission.requires)
                ) {
                    list.push(permission.requires);
                }
            } else {
                // Switching off one that others need switches those off too.
                list = list.filter(
                    (p) =>
                        permissions.find((o) => o.value === p)?.requires !==
                        permission.value,
                );
            }

            return {
                ...data,
                role_permissions: { ...data.role_permissions, [role]: list },
            };
        });
    }

    return (
        <>
            <Head title="Roles" />
            <h1 className="sr-only">Roles</h1>

            <form
                className="space-y-10"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        refund_limits: Object.fromEntries(
                            Object.entries(data.refund_limits).map(([k, v]) => [
                                k,
                                v === '' ? null : v,
                            ]),
                        ),
                    }));
                    form.put(RoleSettingsController.update.url(), {
                        preserveScroll: true,
                    });
                }}
            >
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Roles"
                        description="Each role covers one job in the yard. People can hold several and get everything their roles give. Owners and admins work every yard; everyone else works the yards you give them under Users."
                    />
                    <ul className="divide-y divide-border rounded-lg border border-border">
                        {roles.map((role) => (
                            <li key={role.value} className="p-3 text-sm">
                                <div className="font-medium">{role.label}</div>
                                <div className="text-muted-foreground">
                                    {role.description}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Permissions by role"
                        description="Judgment calls that cut across jobs. Tick the roles that give each one. You can still grant or remove one for a single person under Users. The owner holds every permission."
                    />
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead>
                                <tr className="border-b border-border text-left">
                                    <th className="p-3 font-medium">
                                        Permission
                                    </th>
                                    {roles.map((role) => (
                                        <th
                                            key={role.value}
                                            className="p-2 text-center text-xs font-medium"
                                        >
                                            {role.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {permissions.map((p) => (
                                    <tr key={p.value}>
                                        <td className="p-3 align-top">
                                            <div className="font-medium">
                                                {p.label}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {p.description}
                                            </div>
                                        </td>
                                        {roles.map((role) => {
                                            const checked = role.editable
                                                ? form.data.role_permissions[
                                                      role.value
                                                  ].includes(p.value)
                                                : true;

                                            return (
                                                <td
                                                    key={role.value}
                                                    className="p-2 text-center align-top"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`${role.label}: ${p.label}`}
                                                        className="size-4 rounded border-input accent-foreground"
                                                        checked={checked}
                                                        disabled={
                                                            !role.editable ||
                                                            !p.editable
                                                        }
                                                        onChange={(e) =>
                                                            toggle(
                                                                role.value,
                                                                p,
                                                                e.target
                                                                    .checked,
                                                            )
                                                        }
                                                    />
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Who signs off each quality check is also set per stage
                        under Order picking. Quality-check sign-off here lets a
                        role sign off any stage.
                    </p>
                </section>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Refund limits"
                        description="The most one refund can be. Above it, someone with Refund above limit approves on the spot with their password or time-clock PIN. Leave blank for no limit; 0 means the role can't refund. Sales refunds goods; Rental desk refunds rentals."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        {editable.map((role) => (
                            <div key={role.value} className="grid gap-1.5">
                                <label
                                    htmlFor={`refund-${role.value}`}
                                    className="text-sm font-medium"
                                >
                                    {role.label}
                                </label>
                                <Input
                                    id={`refund-${role.value}`}
                                    inputMode="decimal"
                                    placeholder="No limit"
                                    value={form.data.refund_limits[role.value]}
                                    onChange={(e) =>
                                        form.setData('refund_limits', {
                                            ...form.data.refund_limits,
                                            [role.value]: e.target.value,
                                        })
                                    }
                                />
                                <InputError
                                    message={
                                        errors[`refund_limits.${role.value}`]
                                    }
                                />
                            </div>
                        ))}
                    </div>
                </section>

                <Button type="submit" disabled={form.processing}>
                    Save roles
                </Button>
            </form>
        </>
    );
}
