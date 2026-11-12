<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Writes an event to the service's `outbox` table in the caller's transaction; RelayOutbox moves it to the stream. */
final class Outbox
{
    /** @param array<string, mixed> $payload */
    public function record(string $type, array $payload): void
    {
        DB::table('outbox')->insert([
            'event_id' => (string) Str::ulid(),
            'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
