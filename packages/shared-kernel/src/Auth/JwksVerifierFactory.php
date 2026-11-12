<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Builds a JwtVerifier for services that do not sign tokens: the key set comes from platform's JWKS endpoint. */
final class JwksVerifierFactory
{
    // shortcut: keys are cached for 5 minutes and refreshed only on expiry; a rotated key can fail tokens for up to
    // that long. Refetch on an unknown `kid` when key rotation becomes a real operation.
    private const TTL_SECONDS = 300;

    public function make(): JwtVerifier
    {
        $url = config('jwt.jwks_url') ?: throw new RuntimeException('JWKS_URL is not set.');

        $jwks = Cache::remember('mestaro.jwks', self::TTL_SECONDS, function () use ($url): array {
            $response = Http::timeout(3)->retry(2, 200)->acceptJson()->get($url)->throw();
            $keys = $response->json();
            if (! is_array($keys) || ! is_array($keys['keys'] ?? null) || $keys['keys'] === []) {
                throw new RuntimeException('JWKS endpoint returned no keys.');
            }

            return $keys;
        });

        return new JwtVerifier($jwks, config('jwt.issuer'), config('jwt.audience'));
    }
}
