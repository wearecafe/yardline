<?php

namespace App\Http\Controllers;

use App\Email\BrandSettings;
use App\Models\Tenant;
use App\Support\Files;
use Symfony\Component\HttpFoundation\Response;

/**
 * A yard's logo, for the emails it sends. Public, because email apps fetch
 * images without signing in; the URL carries a version so a new logo isn't
 * hidden behind a cached old one.
 */
class BrandLogoController extends Controller
{
    public function __invoke(Tenant $tenant): Response
    {
        $path = BrandSettings::for($tenant)->logoPath;
        abort_if($path === null || ! Files::disk()->exists($path), 404);

        $type = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';

        return response((string) Files::disk()->get($path), 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
