<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo accounts are shared by every visitor, so their sign-in details,
 * security and business settings can't be changed. Everything else
 * (orders, stock, pricing) is fair game and is rebuilt nightly.
 */
class ProtectDemoAccounts
{
    /**
     * Paths that change an account or the business. Fortify's own routes
     * live under user/ (password, two-factor, profile).
     *
     * @var list<string>
     */
    private const PROTECTED = ['settings/*', 'user/*', '*passkey*'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $request->is(...self::PROTECTED) || ! Demo::isDemoUser($request->user())) {
            return $next($request);
        }

        $message = 'Account and business settings can’t be changed in the demo yard.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
