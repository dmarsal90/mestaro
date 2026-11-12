<?php

namespace Mestaro\Kernel\Messaging;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Consumes the event stream with a consumer group (at-least-once). A message is acknowledged only after
 * the service's EventHandler finished; handlers are idempotent, so redelivery after a crash is harmless.
 * A message whose handler keeps failing is retried every --claim-idle ms; after --max-deliveries attempts it is
 * moved to the dead-letter stream (events.dead_stream) and acknowledged, so it cannot block or loop forever.
 * The consumer group defaults to the service name (config kernel.service).
 */
final class ConsumeEvents extends Command
{
    protected $signature = 'events:consume {--once : Process what is available and exit} {--group= : Consumer group (default: the service name)} {--batch=10} {--claim-idle=60000 : ms before a pending message is reclaimed} {--max-deliveries=5}';

    protected $description = 'Consume domain events from the Redis Stream';

    public function handle(EventHandler $handler): int
    {
        $stream = config('events.stream');
        $group = (string) ($this->option('group') ?: config('kernel.service'));
        $consumer = gethostname() ?: 'consumer';
        $batch = max(1, (int) $this->option('batch'));
        $once = (bool) $this->option('once');

        $this->ensureGroup($stream, $group);

        do {
            // Pending messages (a crashed consumer's, or ours that failed) come back after claim-idle.
            $claimed = Redis::xautoclaim($stream, $group, $consumer, (int) $this->option('claim-idle'), '0-0', $batch);
            $entries = is_array($claimed) ? ($claimed[1] ?? []) : throw new RuntimeException('XAUTOCLAIM failed.');

            $read = Redis::xreadgroup($group, $consumer, [$stream => '>'], $batch, $once ? null : 1000);
            if ($read === false) {
                throw new RuntimeException('XREADGROUP failed.');
            }
            $entries += $read === [] ? [] : reset($read);

            foreach ($entries as $id => $fields) {
                if (isset($claimed[1][$id]) && $this->deliveries($stream, $group, $id) > (int) $this->option('max-deliveries')) {
                    $this->deadLetter($stream, $group, $id, $fields);

                    continue;
                }
                $this->process($stream, $group, $id, $fields, $handler);
            }
        } while (! $once);

        return self::SUCCESS;
    }

    private function deliveries(string $stream, string $group, string $id): int
    {
        return (int) (Redis::xpending($stream, $group, $id, $id, 1)[0][3] ?? 0);
    }

    private function deadLetter(string $stream, string $group, string $id, mixed $fields): void
    {
        // An entry trimmed from the stream while pending has no fields left to keep.
        $entry = is_array($fields) ? $fields : [];
        if (Redis::xadd(config('events.dead_stream'), '*', $entry + ['source_id' => $id, 'dead_at' => now()->toIso8601String()]) === false) {
            throw new RuntimeException("XADD to the dead-letter stream failed for entry {$id}");
        }
        Redis::xack($stream, $group, [$id]);
        Log::error('Event moved to the dead-letter stream after repeated failures.', ['entry' => $id, 'type' => $entry['type'] ?? null]);
    }

    private function ensureGroup(string $stream, string $group): void
    {
        // From '0' so events published before the first start are not lost. false also means "group exists".
        if (Redis::xgroup('CREATE', $stream, $group, '0', true) === false
            && ! str_contains((string) Redis::connection()->client()->getLastError(), 'BUSYGROUP')) {
            throw new RuntimeException('XGROUP CREATE failed.');
        }
    }

    private function process(string $stream, string $group, string $id, mixed $fields, EventHandler $handler): void
    {
        // Entries trimmed from the stream while pending come back without fields: nothing to handle, just ack.
        if (is_array($fields)) {
            try {
                $handler->handle($id, $fields);
            } catch (Throwable $e) {
                report($e);

                return; // not acknowledged: reclaimed and retried later
            }
        }

        Redis::xack($stream, $group, [$id]);
    }
}
