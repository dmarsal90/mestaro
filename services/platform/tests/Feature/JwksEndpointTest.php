<?php

use App\Infrastructure\Auth\JwtKeys;
use Firebase\JWT\JWT;

it('serves the public JWKS without a session', function () {
    config(['jwt.private_key' => JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    app()->forgetInstance(JwtKeys::class);

    $response = $this->get('/.well-known/jwks.json')->assertOk();

    expect($response->json('keys.0.kid'))->toBe(app(JwtKeys::class)->kid)
        ->and($response->headers->get('Set-Cookie'))->toBeNull()
        ->and($response->getContent())->not->toContain('"d"');
});

it('fails closed when no signing key is configured', function () {
    config(['jwt.private_key' => '']);
    app()->forgetInstance(JwtKeys::class);

    $this->get('/.well-known/jwks.json')->assertServerError();
});
