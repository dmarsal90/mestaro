<?php

declare(strict_types=1);

use App\Application\EventPublisher;
use App\Application\Tenancy\TenantDatabaseCreator;
use App\Domain\Tenancy\Restaurant;
use App\Domain\Tenancy\RestaurantRepository;
use App\Domain\Tenancy\RestaurantStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

const CONSUMER_TENANT = '01JABCDEFGHJKMNPQRSTVWXYZ0';

beforeEach(function () {
    config(['events.stream' => 'test.events.'.Str::ulid()]);
});

afterEach(function () {
    Redis::del(config('events.stream'));
    $db = DB::connectUsing('tenant-cleanup', config('database.connections.pgsql'), true);
    $db->statement('drop database if exists "tenant_'.strtolower(CONSUMER_TENANT).'"');
    $db->statement('drop role if exists "tenant_'.strtolower(CONSUMER_TENANT).'"');
});

function registerAndRelay(string $id = CONSUMER_TENANT): void
{
    $restaurant = Restaurant::register($id, 'La Taberna', 'taberna');
    app(RestaurantRepository::class)->save($restaurant);
    app(EventPublisher::class)->publish(...$restaurant->pullEvents());
    test()->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
}

function pendingCount(): int
{
    return (int) (Redis::xpending(config('events.stream'), 'platform')[0] ?? 0);
}

it('provisions the tenant database, activates the restaurant and acknowledges the event', function () {
    registerAndRelay();

    $this->artisan('events:consume', ['--once' => true])->assertSuccessful();

    expect(app(RestaurantRepository::class)->find(CONSUMER_TENANT)?->status())->toBe(RestaurantStatus::Active)
        ->and(DB::selectOne('select count(*) as n from pg_database where datname = ?', ['tenant_'.strtolower(CONSUMER_TENANT)])->n)->toBe(1)
        ->and(pendingCount())->toBe(0);
});

it('handles a redelivered event without side effects', function () {
    registerAndRelay();
    $this->artisan('events:consume', ['--once' => true])->assertSuccessful();
    $entry = array_values(Redis::xrange(config('events.stream'), '-', '+'))[0];
    Redis::xadd(config('events.stream'), '*', $entry);

    $this->artisan('events:consume', ['--once' => true])->assertSuccessful();

    expect(app(RestaurantRepository::class)->find(CONSUMER_TENANT)?->status())->toBe(RestaurantStatus::Active)
        ->and(DB::table('restaurant_provisioning')->count())->toBe(1)
        ->and(pendingCount())->toBe(0);
});

it('reclaims a message left pending by a crashed consumer', function () {
    registerAndRelay();
    Redis::xgroup('CREATE', config('events.stream'), 'platform', '0', true);
    Redis::xreadgroup('platform', 'crashed', [config('events.stream') => '>'], 10);
    expect(pendingCount())->toBe(1);

    $this->artisan('events:consume', ['--once' => true, '--claim-idle' => 0])->assertSuccessful();

    expect(app(RestaurantRepository::class)->find(CONSUMER_TENANT)?->status())->toBe(RestaurantStatus::Active)
        ->and(pendingCount())->toBe(0);
});

it('drops events for unknown restaurants and ignores other event types', function () {
    Redis::xadd(config('events.stream'), '*', ['event_id' => 'e1', 'type' => 'TenantRegistered', 'occurred_at' => 'x', 'payload' => json_encode(['tenant_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])]);
    Redis::xadd(config('events.stream'), '*', ['event_id' => 'e2', 'type' => 'SomethingElse', 'occurred_at' => 'x', 'payload' => '{}']);

    $this->artisan('events:consume', ['--once' => true])->assertSuccessful();

    expect(pendingCount())->toBe(0);
});

it('marks provisioning failed with a safe reason when the database cannot be created', function () {
    $this->app->bind(TenantDatabaseCreator::class, fn () => new class implements TenantDatabaseCreator
    {
        public function create(string $tenantId): void
        {
            throw new RuntimeException('password authentication failed for user "secret"');
        }
    });
    registerAndRelay();

    $this->artisan('events:consume', ['--once' => true])->assertSuccessful();

    $restaurant = app(RestaurantRepository::class)->find(CONSUMER_TENANT);
    expect($restaurant?->status())->toBe(RestaurantStatus::ProvisioningFailed)
        ->and($restaurant?->failureReason())->toBe('platform: database creation failed')
        ->and(pendingCount())->toBe(0);
});
