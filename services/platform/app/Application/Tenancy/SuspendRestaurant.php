<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\Tenancy\RestaurantRepository;

final readonly class SuspendRestaurant
{
    public function __construct(private RestaurantRepository $restaurants) {}

    public function __invoke(string $id): void
    {
        $restaurant = $this->restaurants->find($id, forUpdate: true) ?? throw new RestaurantNotFound($id);
        $restaurant->suspend();
        $this->restaurants->save($restaurant);
    }
}
