<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Application\Tenancy\TenantDatabaseCreator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostgresTenantDatabaseCreator implements TenantDatabaseCreator
{
    public function create(string $tenantId): void
    {
        // DDL cannot take bind parameters: the name is derived from a validated ULID, never from free text.
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $tenantId) !== 1) {
            throw new InvalidArgumentException('Tenant id must be a ULID.');
        }
        $name = 'tenant_'.strtolower($tenantId);

        // Own connection: CREATE DATABASE refuses to run inside a transaction.
        $db = DB::connectUsing('tenant-provisioner', config('database.connections.'.config('database.default')), true);

        // shortcut: NOLOGIN owner, so there is no secret to store yet; services get a login role once the
        // secret store is decided (before the first service connects to a tenant database).
        if (! $db->selectOne('select 1 from pg_roles where rolname = ?', [$name])) {
            $db->statement("create role \"{$name}\" nologin");
        }
        // The provisioning role must be able to SET ROLE to the owner for CREATE DATABASE ... OWNER.
        $db->statement("grant \"{$name}\" to current_user");
        if (! $db->selectOne('select 1 from pg_database where datname = ?', [$name])) {
            $db->statement("create database \"{$name}\" owner \"{$name}\"");
        }
    }
}
