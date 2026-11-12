<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mestaro\Kernel\Tenancy\PostgresTenantDatabaseCreator;
use Mestaro\Kernel\Tenancy\TenantDatabaseCreator;

const TENANT = '01JABCDEFGHJKMNPQRSTVWXYZ0';

function tenantDatabase(string $tenant): string
{
    return PostgresTenantDatabaseCreator::nameFor($tenant, config('kernel.service'));
}

function publishEvent(string $type, string $tenant): void
{
    Redis::xadd(config('events.stream'), '*', ['event_id' => (string) Str::ulid(), 'type' => $type, 'occurred_at' => 'x', 'payload' => json_encode(['tenant_id' => $tenant, 'slug' => 'x'])]);
}

function consume(): void
{
    test()->artisan('events:consume', ['--once' => true])->assertSuccessful();
}

/** @return list<array{string, array<string, mixed>}> */
function answers(): array
{
    return DB::table('outbox')->orderBy('id')->get()->map(fn ($r) => [$r->type, json_decode($r->payload, true)])->all();
}

beforeEach(fn () => Redis::del(config('events.stream'), config('events.dead_stream')));

afterEach(function () {
    $db = DB::connectUsing('tenant-cleanup', config('database.connections.pgsql'), true);
    $db->statement('drop database if exists "'.tenantDatabase(TENANT).'" with (force)');
    $db->statement('drop role if exists "'.tenantDatabase(TENANT).'"');
    Redis::del(config('events.stream'), config('events.dead_stream'));
});

it('creates its tenant database and answers TenantProvisioned', function () {
    publishEvent('TenantRegistered', TENANT);
    consume();

    expect(DB::selectOne('select count(*) as n from pg_database where datname = ?', [tenantDatabase(TENANT)])->n)->toBe(1)
        ->and(answers())->toEqual([['TenantProvisioned', ['tenant_id' => TENANT, 'service' => config('kernel.service')]]])
        ->and(DB::table('tenant_credentials')->where('restaurant_id', TENANT)->where('service', config('kernel.service'))->count())->toBe(1);
});

it('survives a redelivered registration: one database, one password, an answer each time', function () {
    publishEvent('TenantRegistered', TENANT);
    publishEvent('TenantRegistered', TENANT);
    consume();

    expect(DB::selectOne('select count(*) as n from pg_database where datname = ?', [tenantDatabase(TENANT)])->n)->toBe(1)
        ->and(DB::table('tenant_credentials')->count())->toBe(1)
        ->and(array_column(answers(), 0))->toBe(['TenantProvisioned', 'TenantProvisioned']);
});

it('reports a generic reason when the database cannot be created', function () {
    $this->app->bind(TenantDatabaseCreator::class, fn () => new class implements TenantDatabaseCreator
    {
        public function create(string $tenantId, string $service, string $password): void
        {
            throw new RuntimeException('FATAL: password authentication failed for user "secret-role"');
        }
    });

    publishEvent('TenantRegistered', TENANT);
    consume();

    expect(answers())->toEqual([['TenantProvisioningFailed', ['tenant_id' => TENANT, 'service' => config('kernel.service'), 'reason' => 'database creation failed']]]);
});

it('drops registrations with an invalid tenant id and ignores other events', function () {
    publishEvent('TenantRegistered', 'not-a-ulid');
    publishEvent('SomethingElse', TENANT);
    consume();

    expect(answers())->toBe([])
        ->and((int) (Redis::xpending(config('events.stream'), config('kernel.service'))[0] ?? 0))->toBe(0);
});
