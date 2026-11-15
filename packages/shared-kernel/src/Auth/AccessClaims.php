<?php

namespace Mestaro\Kernel\Auth;

final readonly class AccessClaims
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $userId,
        public ?string $tenantId,
        public string $role,
        public array $permissions,
        public string $jti = '',
        public int $issuedAt = 0,
        public int $expiresAt = 0,
    ) {}
}
