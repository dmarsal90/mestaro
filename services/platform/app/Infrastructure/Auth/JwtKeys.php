<?php

namespace App\Infrastructure\Auth;

use Firebase\JWT\JWT;
use InvalidArgumentException;

/** Ed25519 signing key held by platform; the public half is published as JWKS. */
final class JwtKeys
{
    public readonly string $kid;

    private readonly string $secret;

    private readonly string $public;

    public function __construct(#[\SensitiveParameter] string $secretBase64Url)
    {
        $secret = JWT::urlsafeB64Decode($secretBase64Url);
        if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('JWT private key must be a base64url Ed25519 secret key.');
        }
        $this->secret = $secret;
        $this->public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $this->kid = substr(hash('sha256', $this->public), 0, 16);
    }

    /** Key material in the form firebase/php-jwt expects for EdDSA signing. */
    public function signingKey(): string
    {
        return JWT::urlsafeB64Encode($this->secret);
    }

    /** @return array{keys: list<array<string, string>>} */
    public function jwks(): array
    {
        return ['keys' => [[
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => JWT::urlsafeB64Encode($this->public),
            'kid' => $this->kid,
            'use' => 'sig',
            'alg' => 'EdDSA',
        ]]];
    }
}
