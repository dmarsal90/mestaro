<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tenancy\Restaurant;
use App\Domain\Tenancy\RestaurantRepository;

final class InMemoryRestaurantRepository implements RestaurantRepository
{
    /** @var array<string, Restaurant> */
    private array $items = [];

    public function save(Restaurant $restaurant): void
    {
        $this->items[$restaurant->id()] = $restaurant;
    }

    public function find(string $id): ?Restaurant
    {
        return $this->items[$id] ?? null;
    }

    public function slugExists(string $slug): bool
    {
        foreach ($this->items as $item) {
            if ($item->slug() === $slug) {
                return true;
            }
        }

        return false;
    }
}
