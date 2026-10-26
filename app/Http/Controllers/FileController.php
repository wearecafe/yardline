<?php

namespace App\Http\Controllers;

use App\Support\Files;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __invoke(string $path): StreamedResponse
    {
        abort_unless(Files::belongsToCurrentTenant($path) && Files::disk()->exists($path), 404);

        return Files::disk()->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
