<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostgresTenantDatabaseCreator implements TenantDatabaseCreator
{
    /** Database and login role of a tenant for one service: derived only from validated ids, never from free text. */
    public static function nameFor(string $tenantId, string $service): string
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $tenantId) !== 1) {
            throw new InvalidArgumentException('Tenant id must be a ULID.');
        }
        if (preg_match('/^[a-z][a-z0-9]{2,19}$/', $service) !== 1) {
            throw new InvalidArgumentException('Service must be 3-20 lowercase letters or digits, starting with a letter.');
        }

        return 'tenant_'.strtolower($tenantId).'_'.$service;
    }

    public function create(string $tenantId, string $service, string $password): void
    {
        // DDL cannot take bind parameters: the name comes from nameFor(), which only accepts a ULID and a short service id.
        $name = self::nameFor($tenantId, $service);

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
