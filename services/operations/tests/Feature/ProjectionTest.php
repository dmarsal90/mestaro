<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mestaro\Kernel\Tenancy\TenantContext;
use Mestaro\Kernel\Tenancy\TenantCredentials;

/** @param array<string, mixed> $data */
function changed(string $entity, array $data, int $sequence, string $tenant = SHOP_A): void
{
    Redis::xadd(config('events.stream'), '*', [
        'event_id' => (string) Str::ulid(), 'type' => ucfirst($entity).'Changed', 'occurred_at' => 'x',
        'payload' => json_encode(['tenant_id' => $tenant, $entity => $data]), 'sequence' => $sequence,
    ]);
    test()->artisan('events:consume', ['--once' => true])->assertSuccessful();
}

/** @return array<string, array<string, mixed>> "kind:id" => data, from the restaurant's own database */
function projection(string $tenant = SHOP_A): array
{
    $context = app(TenantContext::class);
    $context->use($tenant, app(TenantCredentials::class)->existingPasswordFor($tenant, 'operations'));
    try {
        return DB::table('catalog_projection')->get()->mapWithKeys(fn ($r) => ["$r->kind:$r->id" => json_decode($r->data, true)])->all();
    } finally {
        $context->clear();
    }
}

function tableData(string $token, array $extra = []): array
{
    return ['id' => 'T1', 'zone_id' => 'Z1', 'label' => 'M1', 'capacity' => 4, 'is_active' => true, 'qr_token' => $token, ...$extra];
}

/** @return list<array{string, string, string}> */
function qrIndex(): array
{
    return DB::table('qr_tokens')->orderBy('tenant_id')->get()->map(fn ($r) => [$r->tenant_id, $r->table_id, $r->token_hash])->all();
}

it("keeps each restaurant's catalog in its own database", function () {
    changed('category', ['id' => 'C1', 'name' => 'Vinos'], 1);
    changed('product', ['id' => 'P1', 'name' => 'Rioja', 'price' => 1200], 2);
    changed('zone', ['id' => 'Z1', 'name' => 'Terraza'], 1, SHOP_B);

    expect(array_keys(projection()))->toEqualCanonicalizing(['category:C1', 'product:P1'])
        ->and(projection()['product:P1']['price'])->toBe(1200)
        ->and(array_keys(projection(SHOP_B)))->toBe(['zone:Z1']);
});

it('ignores a state older than the one it has, including after a deletion', function () {
    changed('product', ['id' => 'P1', 'price' => 1500], 5);
    changed('product', ['id' => 'P1', 'price' => 1200], 3);
    expect(projection()['product:P1']['price'])->toBe(1500);

    changed('product', ['id' => 'P1', 'deleted' => true], 8);
    changed('product', ['id' => 'P1', 'price' => 1500], 7);
    expect(projection()['product:P1'])->toEqual(['id' => 'P1', 'deleted' => true]);
});

it('indexes the QR token centrally by its hash and keeps it out of the projection', function () {
    changed('table', tableData('old-token'), 1);
    expect(qrIndex())->toBe([[SHOP_A, 'T1', hash('sha256', 'old-token')]])
        ->and(projection()['table:T1'])->not->toHaveKey('qr_token');

    changed('table', tableData('new-token'), 4);
    changed('table', tableData('old-token'), 2); // a late redelivery must not revive the rotated QR

    expect(qrIndex())->toBe([[SHOP_A, 'T1', hash('sha256', 'new-token')]]);
});

it('drops malformed events and keeps those of a restaurant it cannot serve', function () {
    Redis::xadd(config('events.stream'), '*', ['event_id' => 'e', 'type' => 'ProductChanged', 'occurred_at' => 'x', 'payload' => '{"tenant_id":"nope"}', 'sequence' => 1]);
    changed('product', ['id' => 'P1'], 1, '01JCCCCCCCCCCCCCCCCCCCCCC7');

    // Only the unknown restaurant's event stays pending, to be retried and finally dead-lettered.
    expect(Redis::xpending(config('events.stream'), 'operations')[0])->toBe(1)
        ->and(projection())->toBe([]);
});
