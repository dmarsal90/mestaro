<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

final readonly class TenantRegistered
{
    public function __construct(
        public string $tenantId,
        public string $slug,
    ) {}
}
