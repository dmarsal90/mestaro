<?php

declare(strict_types=1);

use App\Application\Tenancy\TenantCredentials;
use App\Application\Tenancy\TenantDatabaseCreator;
use App\Domain\Tenancy\Restaurant;
use App\Domain\Tenancy\RestaurantRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

const TENANT_A = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const TENANT_B = '01JABCDEFGHJKMNPQRSTVWXYZ1';

function tenantName(string $id): string
{
    return 'tenant_'.strtolower($id);
}

function connectAs(string $user, string $password, string $database): Connection
{
    return DB::connectUsing('tenant-'.$user.'-'.$database, [...config('database.connections.pgsql'), 'database' => $database, 'username' => $user, 'password' => $password], true);
}

afterEach(function () {
    $db = DB::connectUsing('tenant-cleanup', config('database.connections.pgsql'), true);
    foreach ([TENANT_A, TENANT_B] as $id) {
        $db->statement('drop database if exists "'.tenantName($id).'"');
        $db->statement('drop role if exists "'.tenantName($id).'"');
    }
});

it('keeps one stable password per tenant and service, stored encrypted', function () {
    app(RestaurantRepository::class)->save(Restaurant::register(TENANT_A, 'La Taberna', 'taberna'));
    $credentials = app(TenantCredentials::class);

    $first = $credentials->passwordFor(TENANT_A, 'platform');

    expect($first)->toHaveLength(48)
        ->and($credentials->passwordFor(TENANT_A, 'platform'))->toBe($first)
        ->and($credentials->passwordFor(TENANT_A, 'catalog'))->not->toBe($first)
        ->and(DB::table('tenant_credentials')->where('service', 'platform')->value('password'))->not->toContain($first);
});

it('lets the tenant role log in to its own database only', function () {
    $creator = app(TenantDatabaseCreator::class);
    $creator->create(TENANT_A, 'password-of-a');
    $creator->create(TENANT_B, 'password-of-b');

    $own = connectAs(tenantName(TENANT_A), 'password-of-a', tenantName(TENANT_A));
    $own->statement('create table t (id int)');

    expect($own->selectOne('select current_user as u')->u)->toBe(tenantName(TENANT_A))
        ->and(fn () => connectAs(tenantName(TENANT_B), 'password-of-b', tenantName(TENANT_A))->selectOne('select 1'))->toThrow(PDOException::class)
        ->and(fn () => connectAs(tenantName(TENANT_A), 'wrong', tenantName(TENANT_A))->selectOne('select 1'))->toThrow(PDOException::class);
});

it('rewrites the password when the role already exists', function () {
    $creator = app(TenantDatabaseCreator::class);
    $creator->create(TENANT_A, 'old-password');
    $creator->create(TENANT_A, "new-pass'word\"; drop role x; --");

    expect(connectAs(tenantName(TENANT_A), "new-pass'word\"; drop role x; --", tenantName(TENANT_A))->selectOne('select 1 as ok')->ok)->toBe(1)
        ->and(fn () => connectAs(tenantName(TENANT_A), 'old-password', tenantName(TENANT_A))->selectOne('select 1'))->toThrow(PDOException::class);
});
