<?php

namespace App\Application\Auth;

final readonly class AccessClaims
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $userId,
        public ?string $tenantId,
        public string $role,
        public array $permissions,
    ) {}
}
