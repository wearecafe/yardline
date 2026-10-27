import { Head, useForm } from '@inertiajs/react';
import OrderPickingController from '@/actions/App/Http/Controllers/Settings/OrderPickingController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/yard/field';
import InputError from '@/components/input-error';

type StageKey = 'picking' | 'picked' | 'loaded' | 'out';
type CheckedStage = 'picked' | 'loaded';

type Check = {
    required: boolean;
    roles: string[];
    different_user: boolean;
};

type Props = {
    settings: {
        stages: Record<StageKey, boolean>;
        checks: Record<CheckedStage, Check>;
    };
    stages: { key: StageKey; label: string }[];
    roles: { value: string; label: string }[];
};

const stageHints: Record<StageKey, string> = {
    picking:
        'A picker claims the order and pulls product. Off: nobody claims it, and open pick lists stay under New until they move on.',
    picked: 'Every line is pulled and staged, quantities confirmed. Off: picking goes straight to loading.',
    loaded: 'Product is on the yard truck, or the customer’s vehicle for pickups. Off: a picked delivery goes straight to the dispatch board.',
    out: 'Delivery orders only: the truck has left the yard. Off: a load stays Loaded until it’s delivered.',
};

/**
 * Settings → Order picking: which stages orders go through at the yard,
 * and where a quality check has to be signed off before an order moves
 * on.
 */
export default function PickingSettings({ settings, stages, roles }: Props) {
    const form = useForm(settings);

    function setStage(stage: StageKey, on: boolean) {
        form.setData((data) => ({
            ...data,
            stages: { ...data.stages, [stage]: on },
            // Switching a stage off switches its check off with it.
            checks:
                !on && (stage === 'picked' || stage === 'loaded')
                    ? {
                          ...data.checks,
                          [stage]: { ...data.checks[stage], required: false },
                      }
                    : data.checks,
        }));
    }

    function setCheck(stage: CheckedStage, patch: Partial<Check>) {
        form.setData((data) => ({
            ...data,
            checks: {
                ...data.checks,
                [stage]: { ...data.checks[stage], ...patch },
            },
        }));
    }

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <>
            <Head title="Order picking" />
            <h1 className="sr-only">Order picking</h1>

            <form
                className="space-y-10"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(OrderPickingController.update.url(), {
                        preserveScroll: true,
                    });
                }}
            >
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Stages"
                        description="The stages an order goes through at the yard. New and Delivered / Picked up are always on. An order skips a stage that's off; orders already in it finish that stage."
                    />
                    <div className="grid gap-4">
                        {stages.map((stage) => (
                            <Checkbox
                                key={stage.key}
                                id={`stage-${stage.key}`}
                                label={stage.label}
                                hint={stageHints[stage.key]}
                                checked={form.data.stages[stage.key]}
                                onChange={(on) => setStage(stage.key, on)}
                            />
                        ))}
                    </div>
                </section>

                {(['picked', 'loaded'] as const).map((stage) => {
                    const check = form.data.checks[stage];
                    const stageOn = form.data.stages[stage];
                    const label = stage === 'picked' ? 'Picked' : 'Loaded';

                    return (
                        <section key={stage} className="space-y-4">
                            <Heading
                                variant="small"
                                title={`Quality check at ${label}`}
                                description={`When it's on, an order marked ${label.toLowerCase()} waits until someone checks it against the pick list and signs off. If they reject it, it goes back to ${stage === 'picked' ? 'Picking' : 'Picked'} with their reason.`}
                            />
                            <Checkbox
                                id={`check-${stage}`}
                                label={`Require a sign-off at ${label}`}
                                hint={
                                    stageOn
                                        ? undefined
                                        : `Switch the ${label} stage on to use this.`
                                }
                                disabled={!stageOn}
                                checked={check.required}
                                onChange={(on) =>
                                    setCheck(stage, { required: on })
                                }
                            />
                            <InputError
                                message={errors[`checks.${stage}.required`]}
                            />

                            {check.required && (
                                <div className="grid gap-4 border-l-2 border-border pl-4">
                                    <fieldset className="grid gap-2">
                                        <legend className="mb-1 text-sm font-medium">
                                            Who can sign off
                                        </legend>
                                        {roles.map((role) => (
                                            <Checkbox
                                                key={role.value}
                                                id={`check-${stage}-${role.value}`}
                                                label={role.label}
                                                checked={check.roles.includes(
                                                    role.value,
                                                )}
                                                onChange={(on) =>
                                                    setCheck(stage, {
                                                        roles: on
                                                            ? [
                                                                  ...check.roles,
                                                                  role.value,
                                                              ]
                                                            : check.roles.filter(
                                                                  (r) =>
                                                                      r !==
                                                                      role.value,
                                                              ),
                                                    })
                                                }
                                            />
                                        ))}
                                        <InputError
                                            message={
                                                errors[`checks.${stage}.roles`]
                                            }
                                        />
                                    </fieldset>
                                    <Checkbox
                                        id={`check-${stage}-different`}
                                        label="Must be checked by someone else"
                                        hint={`Whoever marked it ${label.toLowerCase()} can't sign off their own work.`}
                                        checked={check.different_user}
                                        onChange={(on) =>
                                            setCheck(stage, {
                                                different_user: on,
                                            })
                                        }
                                    />
                                </div>
                            )}
                        </section>
                    );
                })}

                <Button type="submit" disabled={form.processing}>
                    Save order picking settings
                </Button>
            </form>
        </>
    );
}
