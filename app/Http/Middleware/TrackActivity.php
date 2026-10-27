<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Notes when signed-in people use Yardline, for the back office: the
 * user's and tenant's last activity, and one row per user per day (weekly
 * users and retention). Writes at most every few minutes per user.
 */
class TrackActivity
{
    private const EVERY_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User) {
            $this->touch($user);
        }

        return $response;
    }

    private function touch(User $user): void
    {
        $now = now();
        $last = $user->last_seen_at;

        if ($last !== null && $last->gt($now->subMinutes(self::EVERY_MINUTES)) && $last->isSameDay($now)) {
            return;
        }

        try {
            DB::table('users')->where('id', $user->id)->update(['last_seen_at' => $now]);
            $user->last_seen_at = $now;
            $user->syncOriginalAttribute('last_seen_at');

            if ($user->tenant_id === null) {
                return; // Yardline staff
            }

            DB::table('tenants')->where('id', $user->tenant_id)->update(['last_active_at' => $now]);
            DB::table('user_activity_days')->insertOrIgnore([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'day' => $now->toDateString(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
