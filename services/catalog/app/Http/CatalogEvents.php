<?php

namespace App\Http;

use Mestaro\Kernel\Messaging\Outbox;
use Mestaro\Kernel\Tenancy\TenantContext;

/** The new state of a row, for other services' projections. Call inside the transaction that wrote it. */
final readonly class CatalogEvents
{
    public function __construct(private Outbox $outbox, private TenantContext $context) {}

    /**
     * @param  'category'|'product'|'zone'|'table'  $entity
     * @param  array<string, mixed>  $data
     */
    public function changed(string $entity, array $data): void
    {
        $this->outbox->record(ucfirst($entity).'Changed', ['tenant_id' => $this->context->tenantId(), $entity => $data]);
    }
}
