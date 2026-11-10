<?php

use App\Infrastructure\Auth\JwtKeys;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

const PASSWORD = 'correct horse battery';

beforeEach(function () {
    config(['jwt.private_key' => JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    app()->forgetInstance(JwtKeys::class);
});

function adminUser(array $attrs = []): User
{
    return User::factory()->create(['password' => PASSWORD, 'email' => 'admin@example.com', ...$attrs])
        ->forceFill(['is_platform_admin' => true, ...array_intersect_key($attrs, ['is_platform_admin' => 1])]);
}

function loginAs(string $email = 'admin@example.com', string $password = PASSWORD)
{
    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

function bearer(string $token): array
{
    return ['Authorization' => "Bearer $token"];
}

it('logs in a platform admin and the access token opens the admin API', function () {
    adminUser()->save();

    $data = loginAs()->assertOk()->json('data');

    expect($data['token_type'])->toBe('Bearer')->and($data['expires_in'])->toBe(600);
    $this->getJson('/api/v1/admin/restaurants/nope', bearer($data['access_token']))->assertNotFound();
    $this->getJson('/api/v1/auth/me', bearer($data['access_token']))
        ->assertOk()->assertJsonPath('data.email', 'admin@example.com')->assertJsonPath('data.role', 'platform_admin');
});

it('answers the same 401 for wrong password, unknown email, inactive and non-admin users', function () {
    adminUser()->save();
    adminUser(['email' => 'off@example.com', 'is_active' => false])->save();
    User::factory()->create(['email' => 'staff@example.com', 'password' => PASSWORD]);

    $responses = [
        loginAs(password: 'wrong password!!'),
        loginAs('nobody@example.com'),
        loginAs('off@example.com'),
        loginAs('staff@example.com'),
    ];

    foreach ($responses as $r) {
        $r->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
    expect(array_unique(array_map(fn ($r) => $r->getContent(), $responses)))->toHaveCount(1);
});

it('validates login input', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'nope', 'password' => str_repeat('a', 2000)])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('rate limits repeated login attempts', function () {
    adminUser()->save();

    foreach (range(1, 5) as $_) {
        loginAs(password: 'wrong password!!')->assertUnauthorized();
    }

    loginAs()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});

it('stores passwords as argon2id', function () {
    expect(password_get_info(adminUser()->password)['algoName'])->toBe('argon2id');
})->skip(fn () => config('hashing.driver') !== 'argon2id', 'HASH_DRIVER is argon2id only inside the compose stack');

it('rotates refresh tokens: each works once', function () {
    adminUser()->save();
    $first = loginAs()->json('data.refresh_token');

    $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertOk()->json('data.refresh_token');

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertUnauthorized();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second])->assertOk();
});

it('refuses expired, foreign-ability and deactivated refresh tokens', function () {
    $admin = adminUser();
    $admin->save();

    $expired = loginAs()->json('data.refresh_token');
    PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]);
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $expired])->assertUnauthorized();

    $wrongAbility = $admin->createToken('other', ['something-else'])->plainTextToken;
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $wrongAbility])->assertUnauthorized();

    $valid = loginAs()->json('data.refresh_token');
    $admin->forceFill(['is_active' => false])->save();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $valid])->assertUnauthorized();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'garbage'])->assertUnauthorized();
});

it('logout revokes refresh tokens and needs a valid access token', function () {
    adminUser()->save();
    $data = loginAs()->json('data');

    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    $this->postJson('/api/v1/auth/logout', [], bearer($data['access_token']))->assertNoContent();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $data['refresh_token']])->assertUnauthorized();
});

it('creates a platform admin from the console and rejects weak passwords', function () {
    $this->artisan('platform:create-admin', ['email' => 'Boss@Example.com', 'name' => 'Boss'])
        ->expectsQuestion('Password (min 12 characters)', 'short')
        ->assertFailed();
    expect(User::count())->toBe(0);

    $this->artisan('platform:create-admin', ['email' => 'Boss@Example.com', 'name' => 'Boss'])
        ->expectsQuestion('Password (min 12 characters)', PASSWORD)
        ->assertSuccessful();

    $boss = User::firstOrFail();
    expect($boss->email)->toBe('boss@example.com')
        ->and($boss->is_platform_admin)->toBeTrue()
        ->and(Hash::check(PASSWORD, $boss->password))->toBeTrue()
        ->and($boss->public_id)->toHaveLength(26);
});

it('does not let a client-supplied X-Forwarded-For pick its own rate-limit bucket', function () {
    adminUser()->save();

    foreach (range(1, 5) as $i) {
        test()->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong password!!'], ['X-Forwarded-For' => "203.0.113.$i"])->assertUnauthorized();
    }

    test()->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong password!!'], ['X-Forwarded-For' => '203.0.113.99'])->assertStatus(429);
});

it('uses the forwarded client address only from trusted proxies', function () {
    TrustProxies::at('*');
    adminUser()->save();

    foreach (range(1, 5) as $_) {
        test()->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong password!!'], ['X-Forwarded-For' => '203.0.113.1'])->assertUnauthorized();
    }

    // Same account, another client address: its own bucket, so not limited.
    test()->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong password!!'], ['X-Forwarded-For' => '203.0.113.2'])->assertUnauthorized();
    test()->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong password!!'], ['X-Forwarded-For' => '203.0.113.1'])->assertStatus(429);
    TrustProxies::flushState();
});

it('tolerates a non-string email in the rate limiter', function () {
    test()->postJson('/api/v1/auth/login', ['email' => ['a@b.c'], 'password' => 'x'])->assertStatus(422);
});
