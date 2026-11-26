<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mestaro\Kernel\Messaging\EventHandler;
use Mestaro\Kernel\Tenancy\PostgresTenantDatabaseCreator;
use Mestaro\Kernel\Tenancy\ProvisionTenantOnRegistered;
use Mestaro\Kernel\Tenancy\TenantContext;
use Mestaro\Kernel\Tenancy\TenantCredentials;
use RuntimeException;

/**
 * Keeps the local copy of catalog's data and the central QR index (docs/events.md); every other event goes to
 * tenant provisioning. A state is written only if its sequence is newer, so redelivery and disorder are harmless.
 */
final readonly class ProjectCatalog implements EventHandler
{
    private const KINDS = ['CategoryChanged' => 'category', 'ProductChanged' => 'product', 'ZoneChanged' => 'zone', 'TableChanged' => 'table'];

    public function __construct(
        private ProvisionTenantOnRegistered $provision,
        private TenantContext $context,
        private TenantCredentials $credentials,
    ) {}

    public function handle(string $entryId, array $fields): void
    {
        $kind = self::KINDS[$fields['type'] ?? ''] ?? null;
        if ($kind === null) {
            $this->provision->handle($entryId, $fields);

            return;
        }

        $payload = json_decode($fields['payload'] ?? '', true);
        $tenant = $payload['tenant_id'] ?? null;
        $data = $payload[$kind] ?? null;
        $sequence = $fields['sequence'] ?? '';
        $token = $data['qr_token'] ?? null;

        try {
            PostgresTenantDatabaseCreator::nameFor(is_string($tenant) ? $tenant : '', 'operations');
        } catch (InvalidArgumentException) {
            $tenant = null;
        }
        if ($tenant === null || ! is_string($data['id'] ?? null) || ! ctype_digit($sequence) || ($kind === 'table' && ! is_string($token))) {
            Log::warning('Malformed catalog event dropped.', ['entry' => $entryId, 'type' => $fields['type']]);

            return;
        }
        unset($data['qr_token']);

        // Throwing keeps the event pending: retried, and dead-lettered rather than lost if it never succeeds.
        $password = $this->credentials->existingPasswordFor($tenant, 'operations')
            ?? throw new RuntimeException('Catalog event for a restaurant that was never provisioned here.');

        $this->context->use($tenant, $password);
        try {
            DB::statement(
                'insert into catalog_projection (kind, id, sequence, data) values (?, ?, ?, ?)
                 on conflict (kind, id) do update set sequence = excluded.sequence, data = excluded.data
                 where catalog_projection.sequence < excluded.sequence',
                [$kind, $data['id'], $sequence, json_encode($data, JSON_THROW_ON_ERROR)],
            );
        } finally {
            $this->context->clear();
        }

        // Its own sequence guard: a crash between the two writes is repaired by the redelivery.
        if ($kind === 'table') {
            DB::statement(
                'insert into qr_tokens (tenant_id, table_id, sequence, token_hash) values (?, ?, ?, ?)
                 on conflict (tenant_id, table_id) do update set sequence = excluded.sequence, token_hash = excluded.token_hash
                 where qr_tokens.sequence < excluded.sequence',
                [$tenant, $data['id'], $sequence, hash('sha256', $token)],
            );
        }
    }
}
