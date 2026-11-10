<?php

use App\Domain\Tenancy\TenantRegistered;
use App\Infrastructure\Messaging\OutboxEventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    // Own stream per test so the shared dev Redis is never polluted.
    config(['events.stream' => 'test.events.'.Str::ulid()]);
});

afterEach(fn () => Redis::del(config('events.stream')));

it('publishes pending outbox rows to the stream once and marks them published', function () {
    (new OutboxEventPublisher)->publish(new TenantRegistered('01HTENANT', 'labrasa'), new TenantRegistered('01HOTHER', 'otro'));

    $this->artisan('outbox:relay', ['--once' => true, '--batch' => 1])->assertSuccessful();

    $entries = array_values(Redis::xrange(config('events.stream'), '-', '+'));
    expect($entries)->toHaveCount(2)
        ->and($entries[0]['type'])->toBe('TenantRegistered')
        ->and(json_decode($entries[0]['payload'], true))->toEqual(['tenant_id' => '01HTENANT', 'slug' => 'labrasa'])
        ->and(DB::table('outbox')->whereNull('published_at')->count())->toBe(0);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
    expect(Redis::xlen(config('events.stream')))->toBe(2);
});

it('leaves rows pending when Redis fails so they are retried', function () {
    (new OutboxEventPublisher)->publish(new TenantRegistered('01HTENANT', 'labrasa'));
    Redis::set(config('events.stream'), 'not a stream'); // XADD now fails with WRONGTYPE

    expect(fn () => $this->artisan('outbox:relay', ['--once' => true])->run())->toThrow(RuntimeException::class, 'XADD failed');
    expect(DB::table('outbox')->whereNull('published_at')->count())->toBe(1);
});
