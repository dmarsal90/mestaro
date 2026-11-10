<?php

return [
    // base64url Ed25519 secret key. Generate with `php artisan jwt:generate-key`; never commit it.
    'private_key' => env('JWT_PRIVATE_KEY'),
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost')),
    'audience' => env('JWT_AUDIENCE', 'mestaro'),
    'ttl' => (int) env('JWT_TTL', 600),
    // Refresh tokens are revocable Sanctum tokens, rotated on every use.
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 60 * 60 * 24 * 30),
];
