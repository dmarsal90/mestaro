<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mestaro\Kernel\Tenancy\TenantContext;
use Mestaro\Kernel\Tenancy\TenantCredentials;

/** @return list<array{string, array<string, mixed>}> the events in a restaurant's own outbox */
function outboxOf(string $tenant): array
{
    $context = app(TenantContext::class);
    $context->use($tenant, app(TenantCredentials::class)->existingPasswordFor($tenant, 'catalog'));
    try {
        return DB::table('outbox')->orderBy('id')->get()->map(fn ($r) => [$r->type, json_decode($r->payload, true)])->all();
    } finally {
        $context->clear();
    }
}

/** @return array<string, mixed> payload of the latest event */
function lastEvent(string $type): array
{
    $events = outboxOf(FLOOR_A);
    [$last, $payload] = end($events);
    expect($last)->toBe($type)->and($payload['tenant_id'])->toBe(FLOOR_A);

    return $payload;
}

function post_(string $uri, array $body = []): array
{
    return test()->postJson("/api/v1/$uri", $body, staffOf(FLOOR_A))->assertSuccessful()->json('data');
}

beforeEach(fn () => Redis::del(config('events.stream')));
afterEach(fn () => Redis::del(config('events.stream')));

it('publishes the new state of zones and tables, including the QR token', function () {
    $zone = post_('zones', ['name' => 'Terraza']);
    expect(lastEvent('ZoneChanged')['zone'])->toEqual($zone);

    $table = post_('tables', ['zone_id' => $zone['id'], 'label' => 'M1', 'capacity' => 4]);
    expect(lastEvent('TableChanged')['table'])->toEqual($table);

    $rotated = post_("tables/{$table['id']}/qr/rotate");
    expect(lastEvent('TableChanged')['table']['qr_token'])->toBe($rotated['qr_token']);

    $this->patchJson("/api/v1/tables/{$table['id']}", ['is_active' => false], staffOf(FLOOR_A))->assertOk();
    expect(lastEvent('TableChanged')['table']['is_active'])->toBeFalse();
    $this->patchJson("/api/v1/zones/{$zone['id']}", ['is_active' => false], staffOf(FLOOR_A))->assertOk();
    expect(lastEvent('ZoneChanged')['zone']['is_active'])->toBeFalse();
});

it('publishes the whole product after every change to it or its options', function () {
    Storage::fake('local');
    $category = post_('categories', ['name' => 'Bebidas']);
    expect(lastEvent('CategoryChanged')['category'])->toEqual($category);
    $this->patchJson("/api/v1/categories/{$category['id']}", ['is_active' => true, 'name' => 'Vinos'], staffOf(FLOOR_A))->assertOk();
    expect(lastEvent('CategoryChanged')['category']['name'])->toBe('Vinos');

    $product = post_('products', ['category_id' => $category['id'], 'name' => 'Rioja', 'price' => 1200]);
    expect(lastEvent('ProductChanged')['product'])->toEqual($product);
    $this->patchJson("/api/v1/products/{$product['id']}", ['price' => 1500], staffOf(FLOOR_A))->assertOk();
    expect(lastEvent('ProductChanged')['product']['price'])->toBe(1500);

    $variant = post_("products/{$product['id']}/variants", ['name' => 'Copa']);
    expect(lastEvent('ProductChanged')['product']['variants'])->toEqual([$variant]);
    $this->patchJson("/api/v1/products/{$product['id']}/variants/{$variant['id']}", ['price_delta' => 100], staffOf(FLOOR_A))->assertOk();
    expect(lastEvent('ProductChanged')['product']['variants'][0]['price_delta'])->toBe(100);
    $this->deleteJson("/api/v1/products/{$product['id']}/variants/{$variant['id']}", [], staffOf(FLOOR_A))->assertNoContent();
    expect(lastEvent('ProductChanged')['product']['variants'])->toBe([]);

    $this->post("/api/v1/products/{$product['id']}/image", ['image' => pngUpload()], staffOf(FLOOR_A))->assertCreated();
    expect(lastEvent('ProductChanged')['product']['image_url'])->not->toBeNull();
    $this->deleteJson("/api/v1/products/{$product['id']}/image", [], staffOf(FLOOR_A))->assertNoContent();
    expect(lastEvent('ProductChanged')['product']['image_url'])->toBeNull();

    $this->deleteJson("/api/v1/products/{$product['id']}", [], staffOf(FLOOR_A))->assertNoContent();
    expect(lastEvent('ProductChanged')['product'])->toBe(['id' => $product['id'], 'deleted' => true]);
});

it('publishes nothing for a rejected write', function () {
    post_('zones', ['name' => 'Terraza']);
    $this->postJson('/api/v1/zones', ['name' => 'Terraza'], staffOf(FLOOR_A))->assertStatus(409);

    expect(outboxOf(FLOOR_A))->toHaveCount(1)->and(outboxOf(FLOOR_B))->toBe([]);
});

it("relays every restaurant's outbox to the stream with its sequence", function () {
    post_('zones', ['name' => 'Terraza']);
    $this->postJson('/api/v1/zones', ['name' => 'Salón'], staffOf(FLOOR_B))->assertCreated();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    $sent = array_values(Redis::xrange(config('events.stream'), '-', '+'));
    expect(array_map(fn ($e) => json_decode($e['payload'], true)['tenant_id'], $sent))->toEqualCanonicalizing([FLOOR_A, FLOOR_B])
        ->and(array_column($sent, 'type'))->toBe(['ZoneChanged', 'ZoneChanged'])
        ->and((int) $sent[0]['sequence'])->toBeGreaterThan(0);
});

it('keeps relaying the other restaurants when one database is unreachable', function () {
    post_('zones', ['name' => 'Terraza']);
    $this->postJson('/api/v1/zones', ['name' => 'Salón'], staffOf(FLOOR_B))->assertCreated();
    DB::table('tenant_credentials')->where('restaurant_id', FLOOR_A)->update(['restaurant_id' => '01JCCCCCCCCCCCCCCCCCCCCCC7']);

    $this->artisan('outbox:relay', ['--once' => true])->assertFailed();

    expect(Redis::xlen(config('events.stream')))->toBe(1);
});

it('republishes the current state of every restaurant for new projections', function () {
    $zone = post_('zones', ['name' => 'Terraza']);
    $table = post_('tables', ['zone_id' => $zone['id'], 'label' => 'M1', 'capacity' => 4]);
    $category = post_('categories', ['name' => 'Bebidas']);
    $product = post_('products', ['category_id' => $category['id'], 'name' => 'Rioja', 'price' => 1200]);
    $this->deleteJson("/api/v1/products/{$product['id']}", [], staffOf(FLOOR_A))->assertNoContent();
    $this->postJson('/api/v1/zones', ['name' => 'Salón'], staffOf(FLOOR_B))->assertCreated();
    $before = count(outboxOf(FLOOR_A));

    $this->artisan('catalog:publish-state')->assertSuccessful();

    $again = array_slice(outboxOf(FLOOR_A), $before);
    expect(array_column($again, 0))->toBe(['CategoryChanged', 'ZoneChanged', 'ProductChanged', 'TableChanged'])
        ->and($again[2][1]['product'])->toBe(['id' => $product['id'], 'deleted' => true])
        ->and($again[3][1]['table']['qr_token'])->toBe($table['qr_token'])
        ->and(outboxOf(FLOOR_B))->toHaveCount(2);
});
