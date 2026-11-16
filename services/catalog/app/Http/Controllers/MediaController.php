<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Public menu photos. Unauthenticated on purpose (the QR client has no account); names are 160-bit random and immutable. */
final class MediaController
{
    private const TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public static function url(string $tenant, string $name): string
    {
        return "/api/v1/media/$tenant/$name";
    }

    public function show(string $tenant, string $file): Response
    {
        $path = "menu/$tenant/$file"; // both parts are pinned by route constraints: no separators, no dots beyond the extension
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => self::TYPES[pathinfo($file, PATHINFO_EXTENSION)],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
