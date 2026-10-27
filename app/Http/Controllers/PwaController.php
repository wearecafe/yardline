<?php

namespace App\Http\Controllers;

use App\Access\RouteAccess;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where the installed app opens (the manifest's start_url): each person's
 * home screen for their roles, so one install serves the counter, the
 * yard tablet and the driver's phone.
 */
class PwaController extends Controller
{
    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        if ($user->tenant_id === null) {
            return $user->is_platform_staff ? redirect()->route('admin.overview') : redirect()->route('home');
        }

        return redirect()->route(RouteAccess::home($user));
    }
}
