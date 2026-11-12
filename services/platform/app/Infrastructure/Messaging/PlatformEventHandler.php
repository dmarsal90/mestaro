<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Tenancy\ProvisionTenantDatabase;
use App\Application\Tenancy\RecordProvisioningResult;
use App\Application\Tenancy\RestaurantNotFound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mestaro\Kernel\Messaging\EventHandler;

/** Platform's reaction to the tenant provisioning events; any other event type is acknowledged and ignored. */
final readonly class PlatformEventHandler implements EventHandler
{
    public function __construct(
        private ProvisionTenantDatabase $provision,
        private RecordProvisioningResult $record,
    ) {}

    public function handle(string $entryId, array $fields): void
    {
        $payload = json_decode($fields['payload'] ?? '', true);
        $tenant = is_array($payload) && is_string($payload['tenant_id'] ?? null) ? $payload['tenant_id'] : null;
        if ($tenant === null) {
            return;
        }

        try {
            match ($fields['type'] ?? null) {
                'TenantRegistered' => $this->recordLocalResult($tenant),
                'TenantProvisioned' => $this->recordRemoteResult($entryId, $tenant, $payload, null),
                // The reason ends up in the admin API: keep it short.
                'TenantProvisioningFailed' => $this->recordRemoteResult($entryId, $tenant, $payload, mb_substr((string) ($payload['reason'] ?? 'unspecified'), 0, 200)),
                default => null,
            };
        } catch (RestaurantNotFound $e) {
            Log::warning('Event for an unknown restaurant dropped.', ['entry' => $entryId, 'tenant' => $e->getMessage()]);
        }
    }

    private function recordLocalResult(string $tenant): void
    {
        $failure = ($this->provision)($tenant);
        DB::transaction(fn () => ($this->record)($tenant, config('kernel.service'), $failure));
    }

    /** @param array<string, mixed> $payload */
    private function recordRemoteResult(string $entryId, string $tenant, array $payload, ?string $failure): void
    {
        $service = $payload['service'] ?? null;
        // Only services listed in tenancy.services take part; anything else is a bad or stale producer.
        if (! is_string($service) || ! in_array($service, config('tenancy.services'), true)) {
            Log::warning('Provisioning result from an unexpected service dropped.', ['entry' => $entryId, 'tenant' => $tenant]);

            return;
        }
        DB::transaction(fn () => ($this->record)($tenant, $service, $failure));
    }
}
