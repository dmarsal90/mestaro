<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

interface TenantDatabaseCreator
{
    /** Create the tenant's role and database. Idempotent: safe to call again after a partial failure or redelivery. */
    public function create(string $tenantId): void;
}
