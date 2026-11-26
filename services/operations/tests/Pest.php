<?php

use App\Infrastructure\Messaging\ProjectCatalog;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mestaro\Kernel\Tenancy\MigrateTenant;
use Mestaro\Kernel\Tenancy\PostgresTenantDatabaseCreator;
use Mestaro\Kernel\Tenancy\TenantCredentials;
use Mestaro\Kernel\Tenancy\TenantDatabaseCreator;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

// Two provisioned restaurants (own databases, schema migrated).
const SHOP_A = '01JAAAAAAAAAAAAAAAAAAAAAA7';
const SHOP_B = '01JBBBBBBBBBBBBBBBBBBBBBB7';

pest()->beforeEach(function () {
    Redis::del(config('events.stream'), config('events.dead_stream'));
    foreach ([SHOP_A, SHOP_B] as $tenant) {
        app(TenantDatabaseCreator::class)->create($tenant, 'operations', app(TenantCredentials::class)->passwordFor($tenant, 'operations'));
        app(MigrateTenant::class)($tenant);
    }
})->afterEach(function () {
    $db = DB::connectUsing('tenant-cleanup', config('database.connections.pgsql'), true);
    foreach ([SHOP_A, SHOP_B] as $tenant) {
        $name = PostgresTenantDatabaseCreator::nameFor($tenant, 'operations');
        $db->statement('drop database if exists "'.$name.'" with (force)');
        $db->statement('drop role if exists "'.$name.'"');
    }
    Redis::del(config('events.stream'), config('events.dead_stream'));
})->in('Feature/ProjectionTest.php', 'Feature/SessionTest.php', 'Feature/ContractTest.php');

/** Applies an event exactly as the consumer would. @param array<string, mixed> $data */
function project(string $kind, array $data, int $sequence = 1, string $tenant = SHOP_A): void
{
    app(ProjectCatalog::class)->handle('0-1', [
        'type' => ucfirst($kind).'Changed', 'sequence' => (string) $sequence, 'payload' => json_encode(['tenant_id' => $tenant, $kind => $data]),
    ]);
}

/** A table with its zone, as catalog would announce them. Returns the QR token. */
function seedTable(string $id = 'T1', string $tenant = SHOP_A): string
{
    $token = substr(str_repeat($id.substr($tenant, 3, 1), 43), 0, 43);
    project('zone', ['id' => 'Z1', 'name' => 'Terraza', 'is_active' => true], 1, $tenant);
    project('table', ['id' => $id, 'zone_id' => 'Z1', 'label' => "Mesa $id", 'capacity' => 4, 'is_active' => true, 'qr_token' => $token], 1, $tenant);

    return $token;
}

/** @param list<string> $permissions @return array{Authorization: string} */
function staff(string $tenant = SHOP_A, array $permissions = ['orders.view', 'orders.accept']): array
{
    static $key = null;
    $secret = $key ??= sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $public = sodium_crypto_sign_publickey_from_secretkey($secret);
    config(['jwt.jwks_url' => 'http://platform.test/.well-known/jwks.json', 'jwt.issuer' => 'mestaro-platform', 'jwt.audience' => 'mestaro']);
    Http::fake(['platform.test/*' => Http::response(['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig', 'kid' => 'k1', 'x' => JWT::urlsafeB64Encode($public)]]])]);
    $now = time();

    return ['Authorization' => 'Bearer '.JWT::encode([
        'iss' => 'mestaro-platform', 'aud' => 'mestaro', 'sub' => '01JUSER', 'iat' => $now, 'exp' => $now + 600, 'jti' => bin2hex(random_bytes(8)),
        'tenant_id' => $tenant, 'role' => 'x', 'permissions' => $permissions,
    ], JWT::urlsafeB64Encode($secret), 'EdDSA', 'k1')];
}
