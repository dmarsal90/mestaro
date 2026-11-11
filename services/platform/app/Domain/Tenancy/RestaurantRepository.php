<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

interface RestaurantRepository
{
    public function save(Restaurant $restaurant): void;

    /** @param bool $forUpdate Lock the row until the surrounding transaction ends (read-modify-write). */
    public function find(string $id, bool $forUpdate = false): ?Restaurant;

    public function slugExists(string $slug): bool;
}
