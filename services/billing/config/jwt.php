<?php

return [
    // This service only verifies tokens: it never holds a signing key. Keys come from platform's JWKS endpoint.
    'jwks_url' => env('JWKS_URL'),
    'issuer' => env('JWT_ISSUER'),
    'audience' => env('JWT_AUDIENCE', 'mestaro'),
];
