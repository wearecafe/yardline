<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The link preview (Open Graph and Twitter card) for public pages: the home
 * page, the help center and the sign-in pages. Pages behind a sign-in get
 * none, so a shared link to a yard's orders shows nothing about them.
 */
class ShareMeta
{
    public const IMAGE = '/og-image.png';

    public const TITLE = 'YardRunner: inventory, orders and rentals for masonry and landscape supply yards';

    public const DESCRIPTION = 'The counter, the yard, the trucks, the rental fleet and the contractor portal in one system for masonry and landscape supply yards. Accounting goes to QuickBooks Online.';

    /** Middleware that means a page needs someone signed in (or a private link). */
    private const PRIVATE_MIDDLEWARE = ['auth', 'verified', 'portal', 'back-office', 'time', 'signed'];

    /**
     * @param  array<string, mixed>  $page  The Inertia page object.
     * @return array{title: string, description: string, url: string, image: string}|null
     */
    public static function for(Request $request, array $page): ?array
    {
        if (! self::isPublic($request)) {
            return null;
        }

        $props = $page['props'] ?? [];
        [$title, $description] = match ($page['component'] ?? null) {
            'help/article' => [
                ($props['article']['title'] ?? '').' · YardRunner Help',
                $props['article']['summary'] ?? null,
            ],
            'help/category' => [
                ($props['category']['title'] ?? '').' · YardRunner Help',
                $props['category']['summary'] ?? null,
            ],
            'help/index', 'help/search' => [
                'YardRunner Help',
                'Guides for running your yard on YardRunner: the counter, pricing, accounts, fulfillment, rentals and QuickBooks.',
            ],
            default => [self::TITLE, null],
        };

        return [
            'title' => $title,
            'description' => Str::limit($description ?: self::DESCRIPTION, 200),
            'url' => $request->url(),
            'image' => url(self::IMAGE),
        ];
    }

    private static function isPublic(Request $request): bool
    {
        $route = $request->route();
        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $name = Str::before($middleware, ':');
            if (in_array($name, self::PRIVATE_MIDDLEWARE, true)) {
                return false;
            }
        }

        return true;
    }
}
