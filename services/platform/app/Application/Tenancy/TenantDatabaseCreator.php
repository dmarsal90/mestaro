<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

interface TenantDatabaseCreator
{
    /** Create the tenant's login role (with this password) and database. Idempotent: safe after a partial failure or redelivery. */
    public function create(string $tenantId, string $password): void;
}
