import { Checkbox } from '@/components/yard/field';

/**
 * Pickers for the roles and yards of the people who work at a yard, shared
 * by Settings → Users and Team → Employees.
 */

export type RoleOption = {
    value: string;
    label: string;
    description: string;
    yard_scoped: boolean;
};

export type Yard = { id: number; name: string };

/** Clocks in and out and nothing else. Held on its own, email optional. */
export const TIME_ONLY = 'time_only';

/** Whether these roles work only the yards ticked (not owner or admin). */
export function scoped(roles: RoleOption[], values: string[]): boolean {
    return (
        values.length > 0 &&
        !values.includes('admin') &&
        values.every(
            (v) => roles.find((r) => r.value === v)?.yard_scoped ?? true,
        )
    );
}

/** Only time and attendance: no sign-in needed. */
export function timeOnly(values: string[]): boolean {
    return values.length === 1 && values[0] === TIME_ONLY;
}

/**
 * Ticks the roles someone holds. Time & attendance only can't be held
 * with another role, so ticking it clears the rest and the other way
 * round.
 */
export function RolePicker({
    id,
    roles,
    value,
    onChange,
}: {
    id: string;
    roles: RoleOption[];
    value: string[];
    onChange: (values: string[]) => void;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {roles.map((role) => (
                <Checkbox
                    key={role.value}
                    id={`${id}-${role.value}`}
                    label={role.label}
                    hint={role.description}
                    checked={value.includes(role.value)}
                    onChange={(on) => {
                        if (!on) {
                            onChange(value.filter((v) => v !== role.value));
                        } else if (role.value === TIME_ONLY) {
                            onChange([TIME_ONLY]);
                        } else {
                            onChange([
                                ...value.filter((v) => v !== TIME_ONLY),
                                role.value,
                            ]);
                        }
                    }}
                />
            ))}
        </div>
    );
}

/** Ticks the yards someone works. */
export function YardPicker({
    id,
    locations,
    value,
    onChange,
}: {
    id: string;
    locations: Yard[];
    value: number[];
    onChange: (ids: number[]) => void;
}) {
    return (
        <div className="flex flex-wrap gap-x-5 gap-y-2">
            {locations.map((yard) => (
                <Checkbox
                    key={yard.id}
                    id={`${id}-${yard.id}`}
                    label={yard.name}
                    checked={value.includes(yard.id)}
                    onChange={(on) =>
                        onChange(
                            on
                                ? [...value, yard.id]
                                : value.filter((v) => v !== yard.id),
                        )
                    }
                />
            ))}
        </div>
    );
}
