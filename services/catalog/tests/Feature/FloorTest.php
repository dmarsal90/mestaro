<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mestaro\Kernel\Tenancy\MigrateTenant;
use Mestaro\Kernel\Tenancy\PostgresTenantDatabaseCreator;
use Mestaro\Kernel\Tenancy\TenantCredentials;
use Mestaro\Kernel\Tenancy\TenantDatabaseCreator;

const FLOOR_A = '01JAAAAAAAAAAAAAAAAAAAAAA7';
const FLOOR_B = '01JBBBBBBBBBBBBBBBBBBBBBB7';

/** @param list<string> $permissions */
function floorToken(string $tenant, array $permissions): string
{
    $now = time();

    return JWT::encode([
        'iss' => 'mestaro-platform', 'aud' => 'mestaro', 'sub' => '01JUSER', 'iat' => $now, 'exp' => $now + 600, 'jti' => bin2hex(random_bytes(8)),
        'tenant_id' => $tenant, 'role' => 'x', 'permissions' => $permissions,
    ], JWT::urlsafeB64Encode(test()->secret), 'EdDSA', 'k1');
}

/** @param list<string> $permissions @return array{Authorization: string} */
function staffOf(string $tenant, array $permissions = ['floor.manage']): array
{
    return ['Authorization' => 'Bearer '.floorToken($tenant, $permissions)];
}

beforeEach(function () {
    $pair = sodium_crypto_sign_keypair();
    $this->secret = sodium_crypto_sign_secretkey($pair);
    config(['jwt.jwks_url' => 'http://platform.test/.well-known/jwks.json', 'jwt.issuer' => 'mestaro-platform', 'jwt.audience' => 'mestaro']);
    Http::fake(['platform.test/*' => Http::response(['keys' => [[
        'kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig', 'kid' => 'k1', 'x' => JWT::urlsafeB64Encode(sodium_crypto_sign_publickey($pair)),
    ]]])]);
    foreach ([FLOOR_A, FLOOR_B] as $tenant) {
        app(TenantDatabaseCreator::class)->create($tenant, 'catalog', app(TenantCredentials::class)->passwordFor($tenant, 'catalog'));
        app(MigrateTenant::class)($tenant);
    }
});

afterEach(function () {
    $db = DB::connectUsing('tenant-cleanup', config('database.connections.pgsql'), true);
    foreach ([FLOOR_A, FLOOR_B] as $id) {
        $name = PostgresTenantDatabaseCreator::nameFor($id, 'catalog');
        $db->statement('drop database if exists "'.$name.'" with (force)');
        $db->statement('drop role if exists "'.$name.'"');
    }
});

function zone(string $tenant, string $name = 'Terraza'): string
{
    return test()->postJson('/api/v1/zones', ['name' => $name], staffOf($tenant))->assertCreated()->json('data.id');
}

it('creates and updates zones, rejecting duplicate names', function () {
    $id = zone(FLOOR_A);

    $this->postJson('/api/v1/zones', ['name' => 'Terraza'], staffOf(FLOOR_A))->assertStatus(409)->assertJsonPath('error.code', 'ZONE_NAME_TAKEN');
    $this->patchJson("/api/v1/zones/$id", ['name' => 'Salón', 'sort_order' => 2], staffOf(FLOOR_A))->assertOk()->assertJsonPath('data.name', 'Salón');
    $this->patchJson('/api/v1/zones/01JNOPE0000000000000000000', ['name' => 'X'], staffOf(FLOOR_A))->assertNotFound();
    $this->postJson('/api/v1/zones', ['name' => ''], staffOf(FLOOR_A))->assertStatus(422);
    $this->getJson('/api/v1/zones', staffOf(FLOOR_A, []))->assertOk()->assertJsonCount(1, 'data');
});

it('keeps each restaurant to its own zones and tables', function () {
    $a = zone(FLOOR_A);
    $this->postJson('/api/v1/tables', ['zone_id' => $a, 'label' => 'M1', 'capacity' => 4], staffOf(FLOOR_A))->assertCreated();
    zone(FLOOR_B, 'Otra');

    expect(collect($this->getJson('/api/v1/zones', staffOf(FLOOR_B))->json('data'))->pluck('name')->all())->toBe(['Otra']);
    $this->getJson('/api/v1/tables', staffOf(FLOOR_B))->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/tables', ['zone_id' => $a, 'label' => 'M9', 'capacity' => 2], staffOf(FLOOR_B))->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ZONE');
});

it('creates tables with a 43-character QR token and unique labels', function () {
    $zone = zone(FLOOR_A);

    $table = $this->postJson('/api/v1/tables', ['zone_id' => $zone, 'label' => 'M1', 'capacity' => 4], staffOf(FLOOR_A))->assertCreated()->json('data');

    expect($table['qr_token'])->toMatch('/^[A-Za-z0-9_-]{43}$/')->and($table['is_active'])->toBeTrue();
    $this->postJson('/api/v1/tables', ['zone_id' => $zone, 'label' => 'M1', 'capacity' => 2], staffOf(FLOOR_A))->assertStatus(409)->assertJsonPath('error.code', 'TABLE_LABEL_TAKEN');
    $this->postJson('/api/v1/tables', ['zone_id' => $zone, 'label' => 'M2', 'capacity' => 0], staffOf(FLOOR_A))->assertStatus(422);
    $this->patchJson('/api/v1/zones/'.$zone, ['is_active' => false], staffOf(FLOOR_A))->assertOk();
    $this->postJson('/api/v1/tables', ['zone_id' => $zone, 'label' => 'M3', 'capacity' => 2], staffOf(FLOOR_A))->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ZONE');
});

it('updates a table and moves it only to an active zone', function () {
    $one = zone(FLOOR_A);
    $two = zone(FLOOR_A, 'Salón');
    $id = $this->postJson('/api/v1/tables', ['zone_id' => $one, 'label' => 'M1', 'capacity' => 4], staffOf(FLOOR_A))->json('data.id');

    $this->patchJson("/api/v1/tables/$id", ['zone_id' => $two, 'label' => 'S1', 'capacity' => 6, 'is_active' => false], staffOf(FLOOR_A))
        ->assertOk()->assertJsonPath('data.zone_id', $two)->assertJsonPath('data.label', 'S1')->assertJsonPath('data.is_active', false);
    $this->patchJson("/api/v1/tables/$id", ['zone_id' => '01JNOPE0000000000000000000'], staffOf(FLOOR_A))->assertStatus(422);
    $this->patchJson('/api/v1/tables/01JNOPE0000000000000000000', ['capacity' => 2], staffOf(FLOOR_A))->assertNotFound();
});

it('rotates the QR token so only the new one exists', function () {
    $zone = zone(FLOOR_A);
    $table = $this->postJson('/api/v1/tables', ['zone_id' => $zone, 'label' => 'M1', 'capacity' => 4], staffOf(FLOOR_A))->json('data');

    $rotated = $this->postJson("/api/v1/tables/{$table['id']}/qr/rotate", [], staffOf(FLOOR_A))->assertOk()->json('data');

    expect($rotated['qr_token'])->not->toBe($table['qr_token'])->toMatch('/^[A-Za-z0-9_-]{43}$/')->and($rotated['qr_rotated_at'])->not->toBeNull();
    $this->postJson('/api/v1/tables/01JNOPE0000000000000000000/qr/rotate', [], staffOf(FLOOR_A))->assertNotFound();
});

it('lets any staff read the floor, hides QR tokens from them, and reserves writes to floor.manage', function () {
    $zone = zone(FLOOR_A);
    $this->postJson('/api/v1/tables', ['zone_id' => $zone, 'label' => 'M1', 'capacity' => 4], staffOf(FLOOR_A))->assertCreated();
    $waiter = staffOf(FLOOR_A, ['orders.view']);

    $row = $this->getJson('/api/v1/tables', $waiter)->assertOk()->json('data.0');
    expect($row)->not->toHaveKey('qr_token')->and($row['label'])->toBe('M1');
    $this->postJson('/api/v1/zones', ['name' => 'X'], $waiter)->assertForbidden();
    $this->postJson('/api/v1/tables', ['zone_id' => $zone, 'label' => 'M2', 'capacity' => 2], $waiter)->assertForbidden();
    $this->postJson('/api/v1/tables/'.$row['id'].'/qr/rotate', [], $waiter)->assertForbidden();
    $this->getJson('/api/v1/tables')->assertUnauthorized();
});
