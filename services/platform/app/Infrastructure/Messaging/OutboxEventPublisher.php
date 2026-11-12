<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\EventPublisher;
use App\Domain\Tenancy\TenantRegistered;
use InvalidArgumentException;
use Mestaro\Kernel\Messaging\Outbox;

/** Maps domain events to the outbox, in the caller's transaction; the relay moves them to Redis Streams. */
final readonly class OutboxEventPublisher implements EventPublisher
{
    public function __construct(private Outbox $outbox) {}

    public function publish(object ...$events): void
    {
        foreach ($events as $event) {
            [$type, $payload] = match (true) {
                $event instanceof TenantRegistered => ['TenantRegistered', ['tenant_id' => $event->tenantId, 'slug' => $event->slug]],
                default => throw new InvalidArgumentException('Unmapped event '.$event::class),
            };

            $this->outbox->record($type, $payload);
        }
    }
}
