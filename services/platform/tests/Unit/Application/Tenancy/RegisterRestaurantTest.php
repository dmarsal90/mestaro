<?php

declare(strict_types=1);

use App\Application\Tenancy\RegisterRestaurant;
use App\Application\Tenancy\SlugAlreadyTaken;
use App\Domain\Tenancy\RestaurantStatus;
use App\Domain\Tenancy\TenantRegistered;
use Tests\Support\InMemoryEventPublisher;
use Tests\Support\InMemoryRestaurantRepository;

beforeEach(function () {
    $this->repository = new InMemoryRestaurantRepository;
    $this->publisher = new InMemoryEventPublisher;
    $this->useCase = new RegisterRestaurant($this->repository, $this->publisher);
});

it('stores the restaurant as provisioning and publishes TenantRegistered', function () {
    $id = '01JABCDEFGHJKMNPQRSTVWXYZ0';
    ($this->useCase)($id, 'La Taberna', 'taberna');

    expect($this->repository->find($id)?->status())->toBe(RestaurantStatus::Provisioning)
        ->and($this->publisher->published)->toHaveCount(1)
        ->and($this->publisher->published[0])->toBeInstanceOf(TenantRegistered::class);
});

it('refuses a duplicated slug', function () {
    ($this->useCase)('01JABCDEFGHJKMNPQRSTVWXYZ0', 'La Taberna', 'taberna');
    ($this->useCase)('01JABCDEFGHJKMNPQRSTVWXYZ1', 'Otra', 'taberna');
})->throws(SlugAlreadyTaken::class);
