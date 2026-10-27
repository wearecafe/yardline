<?php

namespace App\Http\Controllers\Settings;

use App\Audit\Audit;
use App\Enums\UserRole;
use App\Fulfillment\PickingSettings;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which stages orders go through at the yard, and where a quality check
 * is required before an order can move on.
 */
class OrderPickingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/picking', [
            'settings' => PickingSettings::current()->toArray(),
            'stages' => collect(PickingSettings::STAGES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'roles' => array_map(fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()], UserRole::checkers()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $roles = array_map(fn (UserRole $role) => $role->value, UserRole::checkers());
        $rules = [];

        foreach (array_keys(PickingSettings::STAGES) as $stage) {
            $rules["stages.{$stage}"] = ['required', 'boolean'];
        }

        foreach (PickingSettings::CHECKED as $stage) {
            $rules["checks.{$stage}.required"] = ['required', 'boolean'];
            $rules["checks.{$stage}.roles"] = ['array'];
            $rules["checks.{$stage}.roles.*"] = [Rule::in($roles)];
            $rules["checks.{$stage}.different_user"] = ['required', 'boolean'];
        }

        $validator = validator($request->all(), $rules);

        $validator->after(function (Validator $v) use ($request) {
            foreach (PickingSettings::CHECKED as $stage) {
                $on = (bool) $request->input("checks.{$stage}.required");

                if ($on && ! $request->boolean("stages.{$stage}")) {
                    $v->errors()->add("checks.{$stage}.required", 'Switch the '.PickingSettings::STAGES[$stage].' stage on to require a check there.');
                }

                if ($on && empty($request->input("checks.{$stage}.roles"))) {
                    $v->errors()->add("checks.{$stage}.roles", 'Pick at least one role that can sign off.');
                }
            }
        });

        $data = $validator->validate();

        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        $before = PickingSettings::for($tenant)->toArray();
        $settings = $tenant->settings ?? [];
        /** @var array<string, mixed> $stages */
        $stages = (array) $data['stages'];
        /** @var array<string, array<string, mixed>> $checks */
        $checks = (array) $data['checks'];

        $settings['picking'] = [
            'stages' => array_map(fn ($on) => (bool) $on, $stages),
            'checks' => collect(PickingSettings::CHECKED)->mapWithKeys(fn (string $stage) => [$stage => [
                // Switching a stage off switches its check off with it.
                'required' => (bool) ($stages[$stage] ?? false) && (bool) ($checks[$stage]['required'] ?? false),
                'roles' => array_values(array_unique((array) ($checks[$stage]['roles'] ?? []))),
                'different_user' => (bool) ($checks[$stage]['different_user'] ?? true),
            ]])->all(),
        ];
        $tenant->update(['settings' => $settings]);

        $after = PickingSettings::for($tenant)->toArray();

        if ($before !== $after) {
            Audit::record($tenant, 'updated', ['order picking' => [self::summary($before), self::summary($after)]]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Order picking settings saved.']);

        return back();
    }

    /**
     * "Stages: Picking, Picked, Loaded · Check at Picked (owner, admin)"
     *
     * @param  array{stages: array<string, bool>, checks: array<string, array{required: bool, roles: list<string>, different_user: bool}>}  $settings
     */
    private static function summary(array $settings): string
    {
        $stages = collect($settings['stages'])->filter()->keys()->map(fn (string $s) => PickingSettings::STAGES[$s] ?? $s)->implode(', ');
        $checks = collect($settings['checks'])->filter(fn (array $c) => $c['required'])
            ->map(fn (array $c, string $stage) => 'check at '.(PickingSettings::STAGES[$stage] ?? $stage).' ('.implode(', ', $c['roles']).($c['different_user'] ? ', not their own work' : '').')')
            ->implode('; ');

        return trim('Stages: '.($stages ?: 'none').($checks ? " · {$checks}" : ''));
    }
}
