<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Messaging;

interface EventHandler
{
    /**
     * Handle one stream entry. Must be idempotent: entries are delivered at least once.
     * Returning acknowledges it (also for event types the service does not care about); throwing leaves it
     * pending to be retried, and after --max-deliveries attempts it goes to the dead-letter stream.
     *
     * @param  array<string, string>  $fields  event_id, type, occurred_at, payload (JSON)
     */
    public function handle(string $entryId, array $fields): void;
}
