<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Tenancy;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mestaro\Kernel\Messaging\EventHandler;
use Mestaro\Kernel\Messaging\Outbox;
use Throwable;

/**
 * Event handler of every service except platform: on TenantRegistered it creates this service's database and role
 * for the tenant and answers TenantProvisioned (or TenantProvisioningFailed) through its own outbox. Other event
 * types are ignored. Idempotent: a redelivery repeats the same no-op DDL and publishes the same answer again.
 */
final readonly class ProvisionTenantOnRegistered implements EventHandler
{
    public function __construct(
        private TenantDatabaseCreator $creator,
        private TenantCredentials $credentials,
        private Outbox $outbox,
    ) {}

    public function handle(string $entryId, array $fields): void
    {
        if (($fields['type'] ?? null) !== 'TenantRegistered') {
            return;
        }
        $payload = json_decode($fields['payload'] ?? '', true);
        $tenant = is_array($payload) ? ($payload['tenant_id'] ?? null) : null;
        $service = config('kernel.service');

        try {
            PostgresTenantDatabaseCreator::nameFor(is_string($tenant) ? $tenant : '', $service);
        } catch (InvalidArgumentException) {
            Log::warning('TenantRegistered with an invalid tenant id dropped.', ['entry' => $entryId]);

            return;
        }

        try {
            $this->creator->create($tenant, $service, $this->credentials->passwordFor($tenant, $service));
        } catch (Throwable $e) {
            report($e);
            // The reason is shown in platform's admin API: never put driver messages in it.
            $this->outbox->record('TenantProvisioningFailed', ['tenant_id' => $tenant, 'service' => $service, 'reason' => 'database creation failed']);

            return;
        }

        $this->outbox->record('TenantProvisioned', ['tenant_id' => $tenant, 'service' => $service]);
    }
}
