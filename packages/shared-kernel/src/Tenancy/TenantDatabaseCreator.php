<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Tenancy;

interface TenantDatabaseCreator
{
    /** Create the tenant's login role (with this password) and this service's database. Idempotent: safe after a partial failure or redelivery. */
    public function create(string $tenantId, string $service, string $password): void;
}
