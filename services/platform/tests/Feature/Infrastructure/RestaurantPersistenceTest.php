<?php

declare(strict_types=1);

use App\Application\EventPublisher;
use App\Domain\Tenancy\Restaurant;
use App\Domain\Tenancy\RestaurantRepository;
use App\Domain\Tenancy\RestaurantStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('round-trips a restaurant through PostgreSQL', function () {
    $repository = app(RestaurantRepository::class);
    $restaurant = Restaurant::register('01JABCDEFGHJKMNPQRSTVWXYZ0', 'La Taberna', 'taberna');
    $restaurant->markProvisioningFailed('billing: timeout');
    $repository->save($restaurant);

    $found = $repository->find('01JABCDEFGHJKMNPQRSTVWXYZ0');

    expect($found?->name())->toBe('La Taberna')
        ->and($found?->status())->toBe(RestaurantStatus::ProvisioningFailed)
        ->and($found?->failureReason())->toBe('billing: timeout')
        ->and($repository->slugExists('taberna'))->toBeTrue()
        ->and($repository->slugExists('otra'))->toBeFalse()
        ->and($repository->find('01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeNull();
});

it('updates an existing restaurant instead of duplicating it', function () {
    $repository = app(RestaurantRepository::class);
    $restaurant = Restaurant::register('01JABCDEFGHJKMNPQRSTVWXYZ0', 'La Taberna', 'taberna');
    $repository->save($restaurant);
    $restaurant->markProvisioned();
    $repository->save($restaurant);

    expect(DB::table('restaurants')->count())->toBe(1)
        ->and($repository->find($restaurant->id())?->status())->toBe(RestaurantStatus::Active);
});

it('writes domain events to the outbox in the same transaction', function () {
    $restaurant = Restaurant::register('01JABCDEFGHJKMNPQRSTVWXYZ0', 'La Taberna', 'taberna');

    app(EventPublisher::class)->publish(...$restaurant->pullEvents());

    $row = DB::table('outbox')->first();
    expect($row->type)->toBe('TenantRegistered')
        ->and($row->published_at)->toBeNull()
        ->and(json_decode($row->payload, true))->toEqual(['tenant_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0', 'slug' => 'taberna']);
});

it('rejects a duplicated slug at database level', function () {
    $repository = app(RestaurantRepository::class);
    $repository->save(Restaurant::register('01JABCDEFGHJKMNPQRSTVWXYZ0', 'A', 'taberna'));

    expect(fn () => $repository->save(Restaurant::register('01JABCDEFGHJKMNPQRSTVWXYZ1', 'B', 'taberna')))
        ->toThrow(QueryException::class);
});

it('loads a restaurant with a row lock inside a transaction', function () {
    $repository = app(RestaurantRepository::class);
    $repository->save(Restaurant::register('01JABCDEFGHJKMNPQRSTVWXYZ0', 'La Taberna', 'taberna'));

    DB::enableQueryLog();
    $found = DB::transaction(fn () => $repository->find('01JABCDEFGHJKMNPQRSTVWXYZ0', forUpdate: true));
    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');

    expect($found?->slug())->toBe('taberna')
        ->and($sql)->toContain('for update');
});
