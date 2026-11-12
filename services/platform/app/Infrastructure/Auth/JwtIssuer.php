<?php

namespace App\Infrastructure\Auth;

use App\Application\Auth\TokenIssuer;
use Firebase\JWT\JWT;
use Mestaro\Kernel\Auth\AccessClaims;

final readonly class JwtIssuer implements TokenIssuer
{
    public function __construct(
        private JwtKeys $keys,
        private string $issuer,
        private string $audience,
        private int $ttlSeconds,
    ) {}

    public function issue(AccessClaims $claims): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $claims->userId,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            'jti' => bin2hex(random_bytes(16)),
            'tenant_id' => $claims->tenantId,
            'role' => $claims->role,
            'permissions' => $claims->permissions,
        ], $this->keys->signingKey(), 'EdDSA', $this->keys->kid);
    }
}
