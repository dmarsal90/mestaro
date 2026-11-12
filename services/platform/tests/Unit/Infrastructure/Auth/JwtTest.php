<?php

use App\Infrastructure\Auth\JwtIssuer;
use App\Infrastructure\Auth\JwtKeys;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Auth\InvalidToken;
use Mestaro\Kernel\Auth\JwtVerifier;

function newKeys(): JwtKeys
{
    return new JwtKeys(JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
}

function claims(): AccessClaims
{
    return new AccessClaims('user-1', '01HTENANT', 'waiter', ['orders.create', 'orders.view']);
}

function verifierFor(JwtKeys $keys, string $iss = 'platform', string $aud = 'mestaro'): JwtVerifier
{
    // Consumers only ever see the public JWKS, never the private key.
    return new JwtVerifier($keys->jwks(), $iss, $aud);
}

it('round-trips claims through issue and verify', function () {
    $keys = newKeys();
    $token = (new JwtIssuer($keys, 'platform', 'mestaro', 600))->issue(claims());

    expect(verifierFor($keys)->verify($token))->toEqual(claims());
});

it('issues a ten minute token with kid and jti', function () {
    $keys = newKeys();
    $token = (new JwtIssuer($keys, 'platform', 'mestaro', 600))->issue(claims());
    $headers = new stdClass;
    $payload = JWT::decode($token, JWK_keys($keys), $headers);

    expect($headers->alg)->toBe('EdDSA')
        ->and($headers->kid)->toBe($keys->kid)
        ->and($payload->exp - $payload->iat)->toBe(600)
        ->and($payload->jti)->not->toBeEmpty();
});

function JWK_keys(JwtKeys $keys): array
{
    return JWK::parseKeySet($keys->jwks(), 'EdDSA');
}

it('rejects an expired token', function () {
    $keys = newKeys();
    $token = (new JwtIssuer($keys, 'platform', 'mestaro', -60))->issue(claims());

    verifierFor($keys)->verify($token);
})->throws(InvalidToken::class);

it('rejects another issuer or audience', function (string $iss, string $aud) {
    $keys = newKeys();
    $token = (new JwtIssuer($keys, 'platform', 'mestaro', 600))->issue(claims());

    verifierFor($keys, $iss, $aud)->verify($token);
})->throws(InvalidToken::class)->with([['other', 'mestaro'], ['platform', 'other']]);

it('rejects a tampered payload', function () {
    $keys = newKeys();
    $token = (new JwtIssuer($keys, 'platform', 'mestaro', 600))->issue(claims());
    [$h, $p, $s] = explode('.', $token);
    $forged = json_decode(JWT::urlsafeB64Decode($p), true);
    $forged['role'] = 'owner';

    verifierFor($keys)->verify("$h.".JWT::urlsafeB64Encode(json_encode($forged)).".$s");
})->throws(InvalidToken::class);

it('rejects a token signed by another key even when it claims our kid', function () {
    $ours = newKeys();
    $attacker = newKeys();
    $payload = ['iss' => 'platform', 'aud' => 'mestaro', 'sub' => 'x', 'iat' => time(), 'exp' => time() + 600,
        'role' => 'owner', 'permissions' => [], 'tenant_id' => null];
    $secret = (new ReflectionProperty($attacker, 'secret'))->getValue($attacker);
    $token = JWT::encode($payload, JWT::urlsafeB64Encode($secret), 'EdDSA', $ours->kid);

    verifierFor($ours)->verify($token);
})->throws(InvalidToken::class);

it('rejects alg none', function () {
    $keys = newKeys();
    $b = fn (array $a) => JWT::urlsafeB64Encode(json_encode($a));
    $token = $b(['alg' => 'none', 'typ' => 'JWT', 'kid' => $keys->kid]).'.'.
        $b(['iss' => 'platform', 'aud' => 'mestaro', 'sub' => 'x', 'exp' => time() + 600, 'role' => 'owner', 'permissions' => []]).'.';

    verifierFor($keys)->verify($token);
})->throws(InvalidToken::class);

it('rejects HS256 signed with the public key (algorithm confusion)', function () {
    $keys = newKeys();
    $public = JWT::urlsafeB64Decode($keys->jwks()['keys'][0]['x']);
    $payload = ['iss' => 'platform', 'aud' => 'mestaro', 'sub' => 'x', 'iat' => time(), 'exp' => time() + 600,
        'role' => 'owner', 'permissions' => [], 'tenant_id' => null];

    verifierFor($keys)->verify(JWT::encode($payload, $public, 'HS256', $keys->kid));
})->throws(InvalidToken::class);

it('rejects a token without exp', function () {
    $keys = newKeys();
    $secret = (new ReflectionProperty($keys, 'secret'))->getValue($keys);
    $token = JWT::encode(['iss' => 'platform', 'aud' => 'mestaro', 'sub' => 'x', 'role' => 'owner',
        'permissions' => [], 'tenant_id' => null], JWT::urlsafeB64Encode($secret), 'EdDSA', $keys->kid);

    verifierFor($keys)->verify($token);
})->throws(InvalidToken::class);

it('rejects garbage', function (string $token) {
    verifierFor(newKeys())->verify($token);
})->throws(InvalidToken::class)->with(['', 'abc', 'a.b.c', str_repeat('a', 5000)]);

it('exposes only the public key in the JWKS', function () {
    $json = json_encode(newKeys()->jwks());

    expect($json)->toContain('"kty":"OKP"', '"crv":"Ed25519"')->not->toContain('"d"');
});

it('refuses a malformed private key', function () {
    new JwtKeys('not-a-key');
})->throws(InvalidArgumentException::class);
