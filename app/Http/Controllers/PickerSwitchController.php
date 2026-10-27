<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Yard tablets are shared, so an order picker switches in with their
 * time-clock PIN (Team → Employees) instead of a full sign-in. Only order
 * picker accounts can be switched to this way; anyone else signs in with
 * their password.
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
            ->where('role', UserRole::Picker->value)
            ->find((int) $data['user_id']);

        if ($picker === null || ! (Employee::forUser($picker)?->checkPin($data['pin']) ?? false)) {
            throw ValidationException::withMessages(['pin' => 'That PIN didn’t match.']);
        }

        Audit::record($picker, 'switched in', note: "On a shared tablet, from {$current->name}");

        Auth::guard('web')->login($picker);
        $request->session()->regenerate();

        return to_route('orders.queue');
    }
}
