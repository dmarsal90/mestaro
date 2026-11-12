<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Application\Tenancy\TenantDatabaseCreator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostgresTenantDatabaseCreator implements TenantDatabaseCreator
{
    public function create(string $tenantId, string $password): void
    {
        // DDL cannot take bind parameters: the name is derived from a validated ULID, never from free text.
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $tenantId) !== 1) {
            throw new InvalidArgumentException('Tenant id must be a ULID.');
        }
        $name = 'tenant_'.strtolower($tenantId);

        // Own connection: CREATE DATABASE refuses to run inside a transaction.
        $db = DB::connectUsing('tenant-provisioner', config('database.connections.'.config('database.default')), true);

        // format() quotes server-side (%I identifier, %L literal), so neither the name nor the password is ever
        // interpolated into SQL by PHP. Re-running rewrites the same stored password: harmless.
        $verb = $db->selectOne('select 1 from pg_roles where rolname = ?', [$name]) ? 'alter' : 'create';
        $db->statement($db->selectOne("select format('{$verb} role %I login password %L', ?::text, ?::text) as ddl", [$name, $password])->ddl);
        // The provisioning role must be able to SET ROLE to the owner for CREATE DATABASE ... OWNER.
        $db->statement("grant \"{$name}\" to current_user");
        if (! $db->selectOne('select 1 from pg_database where datname = ?', [$name])) {
            $db->statement("create database \"{$name}\" owner \"{$name}\"");
        }
        // New databases accept connections from every role by default: keep each tenant's role out of the others.
        $db->statement("revoke connect on database \"{$name}\" from public");
    }
}
