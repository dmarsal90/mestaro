<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\EventPublisher;
use App\Domain\Tenancy\TenantRegistered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Persists events in the caller's transaction; a relay (later slice) moves them to Redis Streams. */
final class OutboxEventPublisher implements EventPublisher
{
    public function publish(object ...$events): void
    {
        foreach ($events as $event) {
            [$type, $payload] = match (true) {
                $event instanceof TenantRegistered => ['TenantRegistered', ['tenant_id' => $event->tenantId, 'slug' => $event->slug]],
                default => throw new InvalidArgumentException('Unmapped event '.$event::class),
            };

            DB::table('outbox')->insert([
                'event_id' => (string) Str::ulid(),
                'type' => $type,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
            ]);
        }
    }
}
