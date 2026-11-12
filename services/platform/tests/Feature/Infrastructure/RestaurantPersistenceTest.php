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

it('keeps other connections out of a locked restaurant row until the lock is released', function () {
    $id = '01JABCDEFGHJKMNPQRSTVWXYZ0';
    config(['database.connections.other' => config('database.connections.'.config('database.default'))]);
    $other = DB::connection('other');
    // Committed on its own connection: the test transaction on the default one would otherwise hide it.
    $other->table('restaurants')->insert(['id' => $id, 'name' => 'La Taberna', 'slug' => 'taberna', 'status' => 'provisioning', 'created_at' => now(), 'updated_at' => now()]);
    $probe = fn () => $other->select('select id from restaurants where id = ? for update nowait', [$id]);

    try {
        // The savepoint lets us release the row lock without ending the test transaction.
        DB::beginTransaction();
        app(RestaurantRepository::class)->find($id, forUpdate: true);
        expect($probe)->toThrow(QueryException::class, 'could not obtain lock');
        DB::rollBack();

        expect($probe())->toHaveCount(1);
    } finally {
        $other->table('restaurants')->where('id', $id)->delete();
        DB::purge('other');
    }
});
