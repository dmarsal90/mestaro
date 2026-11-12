<?php

namespace App\Console\Commands;

use App\Application\Tenancy\ProvisionTenantDatabase;
use App\Application\Tenancy\RecordProvisioningResult;
use App\Application\Tenancy\RestaurantNotFound;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Consumes the event stream with a consumer group (at-least-once). A message is acknowledged only after
 * its handler finished; handlers are idempotent, so redelivery after a crash is harmless.
 * shortcut: a message whose handler keeps failing is retried every --claim-idle ms forever; add a delivery
 * counter (XPENDING) and a dead-letter stream if poison messages show up.
 */
final class ConsumeEvents extends Command
{
    protected $signature = 'events:consume {--once : Process what is available and exit} {--group=platform} {--batch=10} {--claim-idle=60000 : ms before a pending message is reclaimed}';

    protected $description = 'Consume domain events from the Redis Stream';

    public function handle(ProvisionTenantDatabase $provision, RecordProvisioningResult $record): int
    {
        $stream = config('events.stream');
        $group = (string) $this->option('group');
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
                $this->process($stream, $group, $id, $fields, $provision, $record);
            }
        } while (! $once);

        return self::SUCCESS;
    }

    private function ensureGroup(string $stream, string $group): void
    {
        // From '0' so events published before the first start are not lost. false also means "group exists".
        if (Redis::xgroup('CREATE', $stream, $group, '0', true) === false
            && ! str_contains((string) Redis::connection()->client()->getLastError(), 'BUSYGROUP')) {
            throw new RuntimeException('XGROUP CREATE failed.');
        }
    }

    private function process(string $stream, string $group, string $id, mixed $fields, ProvisionTenantDatabase $provision, RecordProvisioningResult $record): void
    {
        // Entries trimmed from the stream while pending come back without fields.
        $payload = is_array($fields) ? json_decode($fields['payload'] ?? '', true) : null;

        try {
            if (($fields['type'] ?? null) === 'TenantRegistered' && is_array($payload) && is_string($payload['tenant_id'] ?? null)) {
                $failure = $provision($payload['tenant_id']);
                DB::transaction(fn () => $record($payload['tenant_id'], 'platform', $failure));
            }
            // Other event types are not ours: acknowledge so they do not stay pending.
        } catch (RestaurantNotFound $e) {
            Log::warning('Event for an unknown restaurant dropped.', ['entry' => $id, 'tenant' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return; // not acknowledged: reclaimed and retried later
        }

        Redis::xack($stream, $group, [$id]);
    }
}
