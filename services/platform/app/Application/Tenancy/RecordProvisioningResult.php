<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\Tenancy\RestaurantRepository;

final readonly class RecordProvisioningResult
{
    /** @param list<string> $expected */
    public function __construct(
        private RestaurantRepository $restaurants,
        private array $expected,
    ) {}

    /** Call inside a transaction: it locks the restaurant row. */
    public function __invoke(string $tenantId, string $service, ?string $failure): void
    {
        $restaurant = $this->restaurants->find($tenantId, forUpdate: true) ?? throw new RestaurantNotFound($tenantId);

        if ($failure === null) {
            $restaurant->recordProvisioned($service, $this->expected);
        } else {
            $restaurant->markProvisioningFailed("{$service}: {$failure}");
        }

        $this->restaurants->save($restaurant);
    }
}
