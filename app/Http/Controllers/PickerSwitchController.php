<?php

namespace App\Http\Controllers;

use App\Access\RouteAccess;
use App\Audit\Audit;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Yard tablets and truck devices are shared, so Yard crew, Drivers and
 * Service techs switch in with their time-clock PIN (Team → Employees)
 * instead of a full sign-in. Nobody who also holds Owner or Admin can be
 * switched to this way; they sign in with their password.
 */
class PickerSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $current */
        $current = $request->user();

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ]);

        $picker = User::query()
            ->where('tenant_id', $current->tenant_id)
            ->find((int) $data['user_id']);

        if ($picker === null || ! $picker->switchesWithPin() || ! (Employee::forUser($picker)?->checkPin($data['pin']) ?? false)) {
            throw ValidationException::withMessages(['pin' => 'That PIN didn’t match.']);
        }

        Audit::record($picker, 'switched in', note: "On a shared tablet, from {$current->name}");

        Auth::guard('web')->login($picker);
        $request->session()->regenerate();

        return to_route(RouteAccess::home($picker));
    }
}
