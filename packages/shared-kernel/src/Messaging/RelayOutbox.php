<?php

namespace Mestaro\Kernel\Messaging;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Moves committed outbox rows to the Redis Stream. Delivery is at-least-once: a crash after XADD and
 * before the commit re-sends the row, so consumers must dedupe by event_id.
 * shortcut: no SIGTERM handling and a fixed 1 s idle poll; add pcntl + LISTEN/NOTIFY if latency or shutdown matter.
 */
final class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay {--once : Drain what is pending and exit} {--batch=100}';

    protected $description = 'Publish pending outbox events to the Redis Stream';

    public function handle(): int
    {
        $batch = max(1, (int) $this->option('batch'));

        do {
            $sent = $this->relayBatch($batch);
            if ($sent === 0 && ! $this->option('once')) {
                sleep(1);
            }
        } while (! $this->option('once') || $sent === $batch);

        return self::SUCCESS;
    }

    private function relayBatch(int $limit): int
    {
        return DB::transaction(function () use ($limit) {
            // SKIP LOCKED lets several relays run without sending the same row twice at once.
            $rows = DB::select(
                'select id, event_id, type, payload, occurred_at from outbox where published_at is null order by id limit ? for update skip locked',
                [$limit],
            );

            foreach ($rows as $row) {
                // phpredis reports a failed XADD by returning false, not by throwing.
                $id = Redis::xadd(config('events.stream'), '*', [
                    'event_id' => $row->event_id,
                    'type' => $row->type,
                    'occurred_at' => $row->occurred_at,
                    'payload' => $row->payload,
                ], config('events.stream_maxlen'), true);
                if ($id === false) {
                    throw new RuntimeException("XADD failed for outbox event {$row->event_id}");
                }
            }

            if ($rows !== []) {
                DB::table('outbox')->whereIn('id', array_column($rows, 'id'))->update(['published_at' => now()]);
            }

            return count($rows);
        });
    }
}
