<?php

namespace Mestaro\Kernel\Auth;

use Exception;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/** Verifies access tokens locally from a public JWKS; the same class other services will reuse. */
final readonly class JwtVerifier
{
    private const MAX_LENGTH = 4096;

    /** @var array<string, Key> */
    private array $keys;

    /** @param array<string, mixed> $jwks */
    public function __construct(array $jwks, private string $issuer, private string $audience)
    {
        // The algorithm is pinned by the key set, so alg=none/HS256 tokens never validate.
        $this->keys = JWK::parseKeySet($jwks, 'EdDSA');
    }

    public function verify(string $token): AccessClaims
    {
        if ($token === '' || strlen($token) > self::MAX_LENGTH) {
            throw new InvalidToken('Malformed token.');
        }

        try {
            $p = JWT::decode($token, $this->keys);
        } catch (Exception $e) {
            throw new InvalidToken('Invalid token.', previous: $e);
        }

        $permissions = $p->permissions ?? null;
        if (! isset($p->exp) // decode() only checks exp when present
            || ($p->iss ?? null) !== $this->issuer
            || ! in_array($this->audience, (array) ($p->aud ?? []), true)
            || ! is_string($p->sub ?? null) || $p->sub === ''
            || ! is_string($p->role ?? null)
            || ! (is_null($p->tenant_id ?? null) || is_string($p->tenant_id))
            || ! is_array($permissions) || array_filter($permissions, 'is_string') !== $permissions) {
            throw new InvalidToken('Invalid claims.');
        }

        return new AccessClaims($p->sub, $p->tenant_id ?? null, $p->role, array_values($permissions));
    }
}
