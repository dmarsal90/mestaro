<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Tenancy;

interface TenantCredentials
{
    /** The service's database password for this tenant: the stored one, or a new random one stored first (race-safe, stable across redeliveries). */
    public function passwordFor(string $tenantId, string $service): string;

    /** The stored password, or null when this tenant was never provisioned for the service. Never creates one. */
    public function existingPasswordFor(string $tenantId, string $service): ?string;
}
