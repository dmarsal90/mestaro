<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Application\EventPublisher;
use App\Domain\Tenancy\RestaurantRepository;

final readonly class RetryProvisioning
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private EventPublisher $events,
    ) {}

    public function __invoke(string $id): void
    {
        $restaurant = $this->restaurants->find($id, forUpdate: true) ?? throw new RestaurantNotFound($id);
        $restaurant->retryProvisioning();
        $this->restaurants->save($restaurant);
        $this->events->publish(...$restaurant->pullEvents());
    }
}
