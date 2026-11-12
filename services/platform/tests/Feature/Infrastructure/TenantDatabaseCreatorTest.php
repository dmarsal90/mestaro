<?php

declare(strict_types=1);

use App\Application\Tenancy\TenantDatabaseCreator;
use Illuminate\Support\Facades\DB;

const TENANT_ID = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const TENANT_DB = 'tenant_01jabcdefghjkmnpqrstvwxyz0';

afterEach(function () {
    $db = DB::connectUsing('tenant-cleanup', config('database.connections.pgsql'), true);
    $db->statement('drop database if exists "'.TENANT_DB.'"');
    $db->statement('drop role if exists "'.TENANT_DB.'"');
});

it('creates the tenant role and database, and a second run is a no-op', function () {
    $creator = app(TenantDatabaseCreator::class);

    $creator->create(TENANT_ID);
    $creator->create(TENANT_ID);

    expect(DB::selectOne('select count(*) as n from pg_database where datname = ?', [TENANT_DB])->n)->toBe(1)
        ->and(DB::selectOne('select pg_get_userbyid(datdba) as owner from pg_database where datname = ?', [TENANT_DB])->owner)->toBe(TENANT_DB);
});

it('refuses ids that are not a ULID', function (string $id) {
    app(TenantDatabaseCreator::class)->create($id);
})->with(['', 'taberna', '01JABCDEFGHJKMNPQRSTVWXYZ', '01JABCDEFGHJKMNPQRSTVWXYZ0"; drop database x; --', '../../etc/passwdxxxxxxxxxx', '01jabcdefghjkmnpqrstvwxyz0'])
    ->throws(InvalidArgumentException::class);
