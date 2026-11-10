<?php

declare(strict_types=1);

use App\Domain\Tenancy\Restaurant;
use App\Domain\Tenancy\RestaurantStatus;
use App\Domain\Tenancy\TenantRegistered;

function newRestaurant(): Restaurant
{
    return Restaurant::register('01JABCDEFGHJKMNPQRSTVWXYZ0', 'La Taberna', 'taberna');
}

it('registers in provisioning state and records TenantRegistered', function () {
    $restaurant = newRestaurant();

    expect($restaurant->status())->toBe(RestaurantStatus::Provisioning);

    $events = $restaurant->pullEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TenantRegistered::class)
        ->and($events[0]->tenantId)->toBe('01JABCDEFGHJKMNPQRSTVWXYZ0')
        ->and($events[0]->slug)->toBe('taberna')
        ->and($restaurant->pullEvents())->toBe([]);
});

it('rejects unsafe slugs', function (string $slug) {
    Restaurant::register('01JABCDEFGHJKMNPQRSTVWXYZ0', 'X', $slug);
})->with(['', 'ab', 'Taberna', 'la-taberna', 'a_b_c', '1taberna', "x'; drop", str_repeat('a', 31)])
    ->throws(InvalidArgumentException::class);

it('rejects an empty name', function () {
    Restaurant::register('01JABCDEFGHJKMNPQRSTVWXYZ0', '  ', 'taberna');
})->throws(InvalidArgumentException::class);

it('becomes active when provisioned', function () {
    $restaurant = newRestaurant();
    $restaurant->markProvisioned();

    expect($restaurant->status())->toBe(RestaurantStatus::Active);
});

it('records the failure reason and allows a retry only from failed', function () {
    $restaurant = newRestaurant();
    $restaurant->markProvisioningFailed('catalog: timeout');

    expect($restaurant->status())->toBe(RestaurantStatus::ProvisioningFailed)
        ->and($restaurant->failureReason())->toBe('catalog: timeout');

    $restaurant->pullEvents();
    $restaurant->retryProvisioning();

    expect($restaurant->status())->toBe(RestaurantStatus::Provisioning)
        ->and($restaurant->failureReason())->toBeNull()
        ->and($restaurant->pullEvents())->toHaveCount(1);
});

it('cannot retry unless provisioning failed', function () {
    newRestaurant()->retryProvisioning();
})->throws(DomainException::class);

it('suspends only active restaurants', function () {
    $restaurant = newRestaurant();
    expect(fn () => $restaurant->suspend())->toThrow(DomainException::class);

    $restaurant->markProvisioned();
    $restaurant->suspend();

    expect($restaurant->status())->toBe(RestaurantStatus::Suspended);
});

it('ignores late provisioning results unless provisioning', function () {
    $restaurant = newRestaurant();
    $restaurant->markProvisioned();
    $restaurant->markProvisioningFailed('late');

    expect($restaurant->status())->toBe(RestaurantStatus::Active);
});
