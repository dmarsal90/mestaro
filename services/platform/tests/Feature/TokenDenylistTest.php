<?php

use App\Infrastructure\Auth\JwtKeys;
use Firebase\JWT\JWT;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Auth\TokenDenylist;

beforeEach(function () {
    config(['jwt.private_key' => JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    app()->forgetInstance(JwtKeys::class);
});

function denyClaims(string $user, string $jti, int $issuedAt): AccessClaims
{
    return new AccessClaims($user, null, 'platform_admin', [], $jti, $issuedAt, time() + 600);
}

it('revokes one token without touching the user\'s others', function () {
    $deny = app(TokenDenylist::class);
    $user = bin2hex(random_bytes(8));
    [$a, $b] = [denyClaims($user, bin2hex(random_bytes(8)), time()), denyClaims($user, bin2hex(random_bytes(8)), time())];

    $deny->revokeToken($a);

    expect($deny->isRevoked($a))->toBeTrue()->and($deny->isRevoked($b))->toBeFalse();
});

it('blocks tokens issued before the user cutoff and lets later logins through', function () {
    $deny = app(TokenDenylist::class);
    $user = bin2hex(random_bytes(8));
    $deny->revokeUser($user);

    expect($deny->isRevoked(denyClaims($user, 'x1', time() - 5)))->toBeTrue()
        ->and($deny->isRevoked(denyClaims($user, 'x2', time() + 1)))->toBeFalse();
});

it('rejects the access token after logout', function () {
    platformAdmin(['email' => 'admin@example.com', 'password' => 'correct horse battery']);
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'correct horse battery'])->json('data.access_token');
    $auth = ['Authorization' => "Bearer $token"];

    $this->getJson('/api/v1/auth/me', $auth)->assertOk();
    $this->postJson('/api/v1/auth/logout', [], $auth)->assertNoContent();

    $this->getJson('/api/v1/auth/me', $auth)->assertUnauthorized();
});

it('fails closed when the denylist cannot be read', function () {
    platformAdmin(['email' => 'admin@example.com', 'password' => 'correct horse battery']);
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'correct horse battery'])->json('data.access_token');
    $this->app->instance(TokenDenylist::class, new TokenDenylist(fn () => throw new RuntimeException('redis down'), 600));

    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer $token"])->assertStatus(503)->assertJsonPath('error.code', 'AUTH_UNAVAILABLE');
});
