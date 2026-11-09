<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

interface RestaurantRepository
{
    public function save(Restaurant $restaurant): void;

    public function find(string $id): ?Restaurant;

    public function slugExists(string $slug): bool;
}
