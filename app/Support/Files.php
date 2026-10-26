<?php

namespace App\Support;

use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Private files (inspection photos, signatures, licence scans, agreement
 * PDFs), stored per tenant and served only through FileController.
 */
final class Files
{
    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('yardline.files_disk'));
    }

    public static function directory(string $folder): string
    {
        $tenant = CurrentTenant::id() ?? throw new InvalidArgumentException('No tenant for file storage.');

        return "tenants/{$tenant}/{$folder}";
    }

    public static function storeUpload(UploadedFile $file, string $folder): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = self::directory($folder).'/'.Str::uuid().'.'.$extension;

        self::disk()->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * Store a `data:image/png;base64,...` URL (signatures drawn on a canvas).
     */
    public static function storeDataUrl(string $dataUrl, string $folder): string
    {
        if (! preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match)) {
            throw new InvalidArgumentException('Not an image data URL.');
        }

        $bytes = base64_decode($match[2], true);

        if ($bytes === false || strlen($bytes) > 2_000_000) {
            throw new InvalidArgumentException('Image is invalid or too large.');
        }

        $path = self::directory($folder).'/'.Str::uuid().'.'.($match[1] === 'jpeg' ? 'jpg' : 'png');
        self::disk()->put($path, $bytes);

        return $path;
    }

    public static function storeContents(string $contents, string $folder, string $extension): string
    {
        $path = self::directory($folder).'/'.Str::uuid().'.'.$extension;
        self::disk()->put($path, $contents);

        return $path;
    }

    /**
     * URL to view a stored file, for the signed-in user's own tenant.
     */
    public static function url(?string $path): ?string
    {
        return $path === null ? null : route('files.show', ['path' => $path]);
    }

    public static function belongsToCurrentTenant(string $path): bool
    {
        $tenant = CurrentTenant::id();

        return $tenant !== null
            && str_starts_with($path, "tenants/{$tenant}/")
            && ! str_contains($path, '..');
    }
}
