<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Application\EventPublisher;
use App\Domain\Tenancy\Restaurant;
use App\Domain\Tenancy\RestaurantRepository;

final readonly class RegisterRestaurant
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private EventPublisher $events,
    ) {}

    public function __invoke(string $id, string $name, string $slug): void
    {
        if ($this->restaurants->slugExists($slug)) {
            throw new SlugAlreadyTaken($slug);
        }

        $restaurant = Restaurant::register($id, $name, $slug);
        $this->restaurants->save($restaurant);
        $this->events->publish(...$restaurant->pullEvents());
    }
}
