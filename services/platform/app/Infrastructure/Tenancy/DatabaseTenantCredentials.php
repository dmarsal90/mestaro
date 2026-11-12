<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Application\Tenancy\TenantCredentials;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class DatabaseTenantCredentials implements TenantCredentials
{
    public function passwordFor(string $tenantId, string $service): string
    {
        // First writer wins, then everyone reads the winner: two consumers can never end up with different passwords.
        DB::table('tenant_credentials')->insertOrIgnore([
            'restaurant_id' => $tenantId,
            'service' => $service,
            'password' => Crypt::encryptString(bin2hex(random_bytes(24))),
            'created_at' => now(),
        ]);

        return Crypt::decryptString(DB::table('tenant_credentials')->where('restaurant_id', $tenantId)->where('service', $service)->value('password'));
    }
}
