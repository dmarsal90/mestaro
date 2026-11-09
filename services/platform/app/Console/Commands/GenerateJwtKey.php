<?php

namespace App\Console\Commands;

use Firebase\JWT\JWT;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jwt:generate-key')]
#[Description('Print a new Ed25519 signing key for JWT_PRIVATE_KEY')]
class GenerateJwtKey extends Command
{
    public function handle(): int
    {
        $this->line(JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));

        return self::SUCCESS;
    }
}
