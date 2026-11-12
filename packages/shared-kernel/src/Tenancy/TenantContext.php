<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * The tenant the current request works for. `use()` points the default database connection at that tenant's
 * database for this service; `clear()` puts the previous default back. Without `use()` the default stays the
 * service's own central database, so routes that touch tenant data must sit behind ResolveTenant.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private ?string $previousDefault = null;

    public function use(string $tenantId, #[\SensitiveParameter] string $password): void
    {
        $name = PostgresTenantDatabaseCreator::nameFor($tenantId, config('kernel.service'));
        $central = config('database.connections.'.config('database.default'));

        config(['database.connections.tenant' => [...$central, 'database' => $name, 'username' => $name, 'password' => $password]]);
        DB::purge('tenant');
        $this->previousDefault ??= config('database.default');
        DB::setDefaultConnection('tenant');
        $this->tenantId = $tenantId;
    }

    public function clear(): void
    {
        if ($this->previousDefault !== null) {
            DB::setDefaultConnection($this->previousDefault);
        }
        DB::purge('tenant');
        $this->tenantId = $this->previousDefault = null;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }
}
