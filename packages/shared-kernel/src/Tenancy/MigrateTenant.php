<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Tenancy;

use Illuminate\Support\Facades\Artisan;

/**
 * Brings one tenant's database for this service up to date with the migrations in `kernel.tenant_migrations`
 * (a path under the service, default database/tenant). Idempotent: already-run migrations are skipped, and a
 * service without that directory has nothing to do.
 */
final readonly class MigrateTenant
{
    public function __construct(private TenantContext $context, private TenantCredentials $credentials) {}

    public function __invoke(string $tenantId): void
    {
        $password = $this->credentials->existingPasswordFor($tenantId, config('kernel.service'))
            ?? throw new \RuntimeException('Tenant was never provisioned for this service.');

        $this->context->use($tenantId, $password);
        try {
            $status = Artisan::call('migrate', ['--database' => 'tenant', '--path' => config('kernel.tenant_migrations', 'database/tenant'), '--force' => true]);
            if ($status !== 0) {
                throw new \RuntimeException('Tenant migration failed: '.trim(Artisan::output()));
            }
        } finally {
            $this->context->clear();
        }
    }
}
