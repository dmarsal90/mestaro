<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

interface TenantCredentials
{
    /** The service's database password for this tenant: the stored one, or a new random one stored first (race-safe, stable across redeliveries). */
    public function passwordFor(string $tenantId, string $service): string;
}
