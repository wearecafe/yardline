<?php

namespace App\Http\Controllers\Settings;

use App\Alerts\StaffAlerts;
use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Channels\WebPushMessage;
use App\Notifications\StaffAlert;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alerts on this phone or tablet (web push): turn them on or off for the
 * device in hand, see the other devices that get them, send a test.
 */
class AlertsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('settings/alerts', [
            'publicKey' => WebPushChannel::configured() ? (string) config('yardrunner.push.public_key') : null,
            'demo' => Demo::isCurrentTenant(),
            'alerts' => StaffAlerts::for($user),
            'devices' => $user->pushSubscriptions()
                ->latest('id')
                ->get()
                ->map(fn (PushSubscription $device) => [
                    'id' => $device->id,
                    'device' => $device->device ?? 'Unknown device',
                    'endpoint_hash' => $device->endpoint_hash,
                    'last_used_at' => $device->last_used_at?->toIso8601String(),
                    'created_at' => $device->created_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    /**
     * This device turned alerts on (or is checking in). A device belongs to
     * whoever last signed in on it.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (Demo::isCurrentTenant()) {
            return response()->json(['message' => 'Alerts are off in the demo yard.'], 422);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ]);

        $hash = PushSubscription::hash($data['endpoint']);

        // Across businesses: one browser, one row, owned by whoever's here now.
        $device = PushSubscription::query()->withoutGlobalScopes()->where('endpoint_hash', $hash)->first()
            ?? new PushSubscription(['endpoint' => $data['endpoint'], 'endpoint_hash' => $hash]);

        $device->forceFill([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'device' => self::describe((string) $request->userAgent()),
        ])->save();

        return response()->json(['id' => $device->id, 'endpoint_hash' => $hash]);
    }

    /**
     * Stop alerts on a device: this one (by endpoint) or another of theirs
     * (by id).
     */
    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'endpoint' => ['required_without:id', 'nullable', 'string', 'max:2000'],
            'id' => ['required_without:endpoint', 'nullable', 'integer'],
        ]);

        $user->pushSubscriptions()
            ->when(isset($data['endpoint']), fn ($q) => $q->where('endpoint_hash', PushSubscription::hash((string) $data['endpoint'])))
            ->when(isset($data['id']), fn ($q) => $q->whereKey((int) $data['id']))
            ->delete();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    /**
     * A sample alert on every device they turned alerts on for.
     */
    public function test(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        if ($user->pushSubscriptions()->doesntExist()) {
            return back()->withErrors(['test' => 'Turn alerts on for this device first.']);
        }

        Notification::sendNow($user, new StaffAlert('test', new WebPushMessage(
            title: 'Alerts are on',
            body: 'This is how YardRunner alerts look on this device.',
            url: route('alerts.edit', absolute: false),
            tag: 'test',
        )));

        Inertia::flash('toast', WebPushChannel::configured()
            ? ['type' => 'success', 'message' => 'Test alert sent.']
            : ['type' => 'info', 'message' => 'Push isn’t set up on this server yet (no VAPID keys), so the test was logged.']);

        return back();
    }

    /** "Chrome on Android", "Safari on iPhone"… for the device list. */
    public static function describe(string $agent): string
    {
        $os = match (true) {
            Str::contains($agent, 'iPhone') => 'iPhone',
            Str::contains($agent, 'iPad') => 'iPad',
            Str::contains($agent, 'Android') => 'Android',
            Str::contains($agent, 'Windows') => 'Windows',
            Str::contains($agent, 'Mac OS X') => 'Mac',
            Str::contains($agent, 'CrOS') => 'Chromebook',
            Str::contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            Str::contains($agent, 'Edg/') => 'Edge',
            Str::contains($agent, 'Firefox/') || Str::contains($agent, 'FxiOS') => 'Firefox',
            Str::contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            Str::contains($agent, 'Chrome/') || Str::contains($agent, 'CriOS') => 'Chrome',
            Str::contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        return match (true) {
            $browser !== null && $os !== null => "{$browser} on {$os}",
            default => $browser ?? $os ?? 'Browser',
        };
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        // Alerts belong to a business; YardRunner staff have none.
        abort_unless($user instanceof User && $user->tenant_id !== null, 404);

        return $user;
    }
}
