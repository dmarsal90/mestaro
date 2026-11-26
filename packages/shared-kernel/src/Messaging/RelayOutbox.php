<?php

namespace Mestaro\Kernel\Messaging;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mestaro\Kernel\Tenancy\TenantContext;
use Mestaro\Kernel\Tenancy\TenantCredentials;
use RuntimeException;
use Throwable;

/**
 * Moves committed outbox rows to the Redis Stream. Delivery is at-least-once: a crash after XADD and
 * before the commit re-sends the row, so consumers must dedupe by event_id.
 * Services with `kernel.tenant_outbox` also have an outbox in each restaurant's database, written in the same
 * transaction as the business data; `sequence` (the outbox id) orders the events of one restaurant.
 * shortcut: one connection per restaurant per pass; relay in parallel or use LISTEN/NOTIFY when restaurants number in the hundreds.
 * shortcut: no SIGTERM handling and a fixed 1 s idle poll; add pcntl + LISTEN/NOTIFY if latency or shutdown matter.
 */
final class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay {--once : Drain what is pending and exit} {--batch=100}';

    protected $description = 'Publish pending outbox events to the Redis Stream';

    public function handle(TenantContext $context, TenantCredentials $credentials): int
    {
        $batch = max(1, (int) $this->option('batch'));

        do {
            $failed = false;
            $sent = $this->relayBatch($batch);
            $more = $sent === $batch;

            if (config('kernel.tenant_outbox')) {
                foreach (DB::table('tenant_credentials')->where('service', config('kernel.service'))->pluck('restaurant_id') as $tenant) {
                    // One unreachable restaurant must not hold back the events of the others.
                    try {
                        $context->use($tenant, $credentials->existingPasswordFor($tenant, config('kernel.service')));
                        $n = $this->relayBatch($batch);
                        $sent += $n;
                        $more = $more || $n === $batch;
                    } catch (Throwable $e) {
                        report($e);
                        $failed = true;
                    } finally {
                        $context->clear();
                    }
                }
            }

            if ($sent === 0 && ! $this->option('once')) {
                sleep(1);
            }
        } while (! $this->option('once') || $more);

        return $failed ? self::FAILURE : self::SUCCESS;
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
                    'sequence' => $row->id,
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
