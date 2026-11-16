<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Tenancy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Run on every deploy that adds tenant migrations: new tenants are migrated when provisioned, existing ones here. */
final class MigrateTenants extends Command
{
    protected $signature = 'tenants:migrate';

    protected $description = 'Migrate the database of every tenant provisioned for this service';

    public function handle(MigrateTenant $migrate): int
    {
        $failed = 0;
        foreach (DB::table('tenant_credentials')->where('service', config('kernel.service'))->pluck('restaurant_id') as $tenant) {
            try {
                $migrate($tenant);
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("Tenant $tenant failed.");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
