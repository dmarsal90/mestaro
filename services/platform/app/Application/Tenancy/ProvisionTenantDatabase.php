<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\Tenancy\RestaurantRepository;
use App\Domain\Tenancy\RestaurantStatus;
use Throwable;

final readonly class ProvisionTenantDatabase
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private TenantDatabaseCreator $creator,
    ) {}

    /** @return string|null null on success (or when there is nothing to do), otherwise a safe failure reason */
    public function __invoke(string $tenantId): ?string
    {
        $restaurant = $this->restaurants->find($tenantId) ?? throw new RestaurantNotFound($tenantId);
        if ($restaurant->status() !== RestaurantStatus::Provisioning) {
            return null;
        }

        try {
            $this->creator->create($tenantId);
        } catch (Throwable $e) {
            report($e);

            // The reason is exposed through the admin API: never put driver messages in it.
            return 'database creation failed';
        }

        return null;
    }
}
