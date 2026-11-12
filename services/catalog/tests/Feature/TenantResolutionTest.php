<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mestaro\Kernel\Auth\AuthenticateJwt;
use Mestaro\Kernel\Tenancy\PostgresTenantDatabaseCreator;
use Mestaro\Kernel\Tenancy\ResolveTenant;
use Mestaro\Kernel\Tenancy\TenantCredentials;
use Mestaro\Kernel\Tenancy\TenantDatabaseCreator;

const TENANT_A = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const TENANT_B = '01JABCDEFGHJKMNPQRSTVWXYZ1';
const TENANT_UNKNOWN = '01JABCDEFGHJKMNPQRSTVWXYZ2';

/** Token signed like platform does: Ed25519 / EdDSA, claims as in JwtIssuer. */
function tokenFor(?string $tenant, ?string $secret = null): string
{
    $secret ??= test()->secret;
    $now = time();

    return JWT::encode([
        'iss' => 'mestaro-platform', 'aud' => 'mestaro', 'sub' => '01JUSER', 'iat' => $now, 'exp' => $now + 600,
        'tenant_id' => $tenant, 'role' => 'restaurant_admin', 'permissions' => [],
    ], JWT::urlsafeB64Encode($secret), 'EdDSA', 'k1');
}

function provision(string $tenant, string $marker): void
{
    $password = app(TenantCredentials::class)->passwordFor($tenant, 'catalog');
    app(TenantDatabaseCreator::class)->create($tenant, 'catalog', $password);

    $name = PostgresTenantDatabaseCreator::nameFor($tenant, 'catalog');
    /** @var Connection $db */
    $db = DB::connectUsing('seed-'.$name, [...config('database.connections.pgsql'), 'database' => $name, 'username' => $name, 'password' => $password], true);
    $db->statement('create table marker (owner text)');
    $db->table('marker')->insert(['owner' => $marker]);
}

beforeEach(function () {
    $pair = sodium_crypto_sign_keypair();
    $this->secret = sodium_crypto_sign_secretkey($pair);
    $jwks = ['keys' => [[
        'kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig', 'kid' => 'k1',
        'x' => JWT::urlsafeB64Encode(sodium_crypto_sign_publickey($pair)),
    ]]];
    config(['jwt.jwks_url' => 'http://platform.test/.well-known/jwks.json', 'jwt.issuer' => 'mestaro-platform', 'jwt.audience' => 'mestaro']);
    Http::fake(['platform.test/*' => Http::response($jwks)]);

    // Test-only probe: reports which tenant database the request ended up in and what it can read there.
    Route::middleware([AuthenticateJwt::class, ResolveTenant::class])->get('api/v1/_probe', fn () => response()->json([
        'database' => DB::selectOne('select current_database() as d')->d,
        'marker' => DB::selectOne('select owner from marker')->owner,
    ]));

    provision(TENANT_A, 'A');
    provision(TENANT_B, 'B');
});

afterEach(function () {
    $db = DB::connectUsing('tenant-cleanup', config('database.connections.pgsql'), true);
    foreach ([TENANT_A, TENANT_B] as $id) {
        $name = PostgresTenantDatabaseCreator::nameFor($id, 'catalog');
        $db->statement('drop database if exists "'.$name.'" with (force)');
        $db->statement('drop role if exists "'.$name.'"');
    }
});

it('serves each token its own tenant database and nothing of the other', function () {
    $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer '.tokenFor(TENANT_A)])
        ->assertOk()->assertExactJson(['database' => PostgresTenantDatabaseCreator::nameFor(TENANT_A, 'catalog'), 'marker' => 'A']);
    $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer '.tokenFor(TENANT_B)])
        ->assertOk()->assertExactJson(['database' => PostgresTenantDatabaseCreator::nameFor(TENANT_B, 'catalog'), 'marker' => 'B']);
});

it('ignores a tenant id sent in the request: only the token decides', function () {
    $this->getJson('/api/v1/_probe?tenant_id='.TENANT_B, ['Authorization' => 'Bearer '.tokenFor(TENANT_A), 'X-Restaurant' => TENANT_B])
        ->assertOk()->assertJsonPath('marker', 'A');
});

it('rejects missing, forged and foreign-signed tokens', function () {
    $this->getJson('/api/v1/_probe')->assertUnauthorized();
    $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer not.a.jwt'])->assertUnauthorized();
    $foreign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer '.tokenFor(TENANT_A, $foreign)])->assertUnauthorized();
});

it('fails closed without a tenant in the token', function () {
    $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer '.tokenFor(null)])
        ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
});

it('answers 404 for a tenant never provisioned here and does not create credentials for it', function () {
    $before = DB::table('tenant_credentials')->count();

    $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer '.tokenFor(TENANT_UNKNOWN)])->assertNotFound();

    expect(DB::table('tenant_credentials')->count())->toBe($before);
});

it('puts the default connection back after the request', function () {
    $default = DB::getDefaultConnection();

    $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer '.tokenFor(TENANT_A)])->assertOk();

    expect(DB::getDefaultConnection())->toBe($default);
});

it('does not serve anything when the JWKS endpoint is down', function () {
    config(['jwt.jwks_url' => 'http://down.test/.well-known/jwks.json']);
    Http::fake(['down.test/*' => Http::response('', 503)]);

    $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer '.tokenFor(TENANT_A)])->assertStatus(503)->assertJsonPath('error.code', 'AUTH_UNAVAILABLE')->assertJsonMissing(['marker' => 'A']);
});
