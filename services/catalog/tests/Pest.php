<?php

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mestaro\Kernel\Tenancy\MigrateTenant;
use Mestaro\Kernel\Tenancy\PostgresTenantDatabaseCreator;
use Mestaro\Kernel\Tenancy\TenantCredentials;
use Mestaro\Kernel\Tenancy\TenantDatabaseCreator;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

// Two provisioned restaurants (own databases, schema migrated) for the tests of tenant routes.
const FLOOR_A = '01JAAAAAAAAAAAAAAAAAAAAAA7';
const FLOOR_B = '01JBBBBBBBBBBBBBBBBBBBBBB7';

/** @param list<string> $permissions @return array{Authorization: string} */
function staffOf(string $tenant, array $permissions = ['floor.manage', 'menu.manage']): array
{
    $now = time();
    $token = JWT::encode([
        'iss' => 'mestaro-platform', 'aud' => 'mestaro', 'sub' => '01JUSER', 'iat' => $now, 'exp' => $now + 600, 'jti' => bin2hex(random_bytes(8)),
        'tenant_id' => $tenant, 'role' => 'x', 'permissions' => $permissions,
    ], JWT::urlsafeB64Encode(test()->secret), 'EdDSA', 'k1');

    return ['Authorization' => "Bearer $token"];
}

pest()->beforeEach(function () {
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
})->afterEach(function () {
    $db = DB::connectUsing('tenant-cleanup', config('database.connections.pgsql'), true);
    foreach ([FLOOR_A, FLOOR_B] as $id) {
        $name = PostgresTenantDatabaseCreator::nameFor($id, 'catalog');
        $db->statement('drop database if exists "'.$name.'" with (force)');
        $db->statement('drop role if exists "'.$name.'"');
    }
})->in('Feature/FloorTest.php', 'Feature/MenuTest.php');
