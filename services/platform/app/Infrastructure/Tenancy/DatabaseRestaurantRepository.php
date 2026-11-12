<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Domain\Tenancy\Restaurant;
use App\Domain\Tenancy\RestaurantRepository;
use App\Domain\Tenancy\RestaurantStatus;
use Illuminate\Support\Facades\DB;

final class DatabaseRestaurantRepository implements RestaurantRepository
{
    public function save(Restaurant $restaurant): void
    {
        $now = now();

        DB::table('restaurants')->upsert([[
            'id' => $restaurant->id(),
            'name' => $restaurant->name(),
            'slug' => $restaurant->slug(),
            'status' => $restaurant->status()->value,
            'failure_reason' => $restaurant->failureReason(),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['status', 'failure_reason', 'updated_at']);

        DB::table('restaurant_provisioning')->insertOrIgnore(array_map(
            fn (string $service) => ['restaurant_id' => $restaurant->id(), 'service' => $service, 'created_at' => $now],
            $restaurant->provisionedServices(),
        ));
    }

    public function find(string $id, bool $forUpdate = false): ?Restaurant
    {
        $query = DB::table('restaurants')->where('id', $id);
        $row = ($forUpdate ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : Restaurant::reconstitute(
            $row->id,
            $row->name,
            $row->slug,
            RestaurantStatus::from($row->status),
            $row->failure_reason,
            DB::table('restaurant_provisioning')->where('restaurant_id', $id)->pluck('service')->all(),
        );
    }

    public function slugExists(string $slug): bool
    {
        return DB::table('restaurants')->where('slug', $slug)->exists();
    }
}
