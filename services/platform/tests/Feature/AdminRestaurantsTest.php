<?php

use App\Application\Auth\AccessClaims;
use App\Application\Auth\TokenIssuer;
use App\Infrastructure\Auth\JwtKeys;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['jwt.private_key' => JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    app()->forgetInstance(JwtKeys::class);
});

function adminHeaders(string $role = 'platform_admin', ?string $tenant = null): array
{
    $token = app(TokenIssuer::class)->issue(new AccessClaims('u1', $tenant, $role, []));

    return ['Authorization' => "Bearer $token"];
}

function registerAcme(): string
{
    return test()->postJson('/api/v1/admin/restaurants', ['name' => 'La Brasa', 'slug' => 'labrasa'], adminHeaders())->json('data.id');
}

it('rejects missing, forged, non-admin and tenant-scoped tokens', function () {
    $this->postJson('/api/v1/admin/restaurants', [])->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->postJson('/api/v1/admin/restaurants', [], ['Authorization' => 'Bearer garbage'])->assertUnauthorized();
    $this->postJson('/api/v1/admin/restaurants', [], adminHeaders('waiter'))->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    $this->postJson('/api/v1/admin/restaurants', [], adminHeaders(tenant: '01HTENANT'))->assertForbidden();
});

it('registers a restaurant as provisioning and writes the outbox event', function () {
    $this->postJson('/api/v1/admin/restaurants', ['name' => 'La Brasa', 'slug' => 'labrasa'], adminHeaders())
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.slug', 'labrasa');

    expect(DB::table('outbox')->count())->toBe(1);
});

it('validates input and refuses a duplicated slug', function () {
    $this->postJson('/api/v1/admin/restaurants', ['name' => '', 'slug' => 'Bad Slug'], adminHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['name', 'slug']]]);

    registerAcme();
    $this->postJson('/api/v1/admin/restaurants', ['name' => 'Otra', 'slug' => 'labrasa'], adminHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SLUG_TAKEN');
});

it('shows a restaurant and answers 404 for an unknown one', function () {
    $id = registerAcme();

    $this->getJson("/api/v1/admin/restaurants/$id", adminHeaders())->assertOk()->assertJsonPath('data.id', $id);
    $this->getJson('/api/v1/admin/restaurants/nope', adminHeaders())->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
});

it('only suspends active restaurants and only retries failed provisioning', function () {
    $id = registerAcme();

    $this->postJson("/api/v1/admin/restaurants/$id/suspend", [], adminHeaders())
        ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    $this->postJson("/api/v1/admin/restaurants/$id/retry-provisioning", [], adminHeaders())->assertStatus(409);

    DB::table('restaurants')->where('id', $id)->update(['status' => 'provisioning_failed', 'failure_reason' => 'boom']);
    $this->postJson("/api/v1/admin/restaurants/$id/retry-provisioning", [], adminHeaders())
        ->assertStatus(202)->assertJsonPath('data.status', 'provisioning');
    expect(DB::table('outbox')->count())->toBe(2);

    DB::table('restaurants')->where('id', $id)->update(['status' => 'active']);
    $this->postJson("/api/v1/admin/restaurants/$id/suspend", [], adminHeaders())
        ->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->postJson('/api/v1/admin/restaurants/nope/suspend', [], adminHeaders())->assertNotFound();
});
