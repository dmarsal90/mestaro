<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use DomainException;
use InvalidArgumentException;

final class Restaurant
{
    /** @var list<object> */
    private array $events = [];

    private function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $slug,
        private RestaurantStatus $status,
        private ?string $failureReason,
        /** @var list<string> services that already reported a successful provisioning */
        private array $provisioned = [],
    ) {}

    /** The slug ends up in database and role names, so it is restricted to a safe alphabet. */
    public static function register(string $id, string $name, string $slug): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Restaurant name is required.');
        }
        if (preg_match('/^[a-z][a-z0-9]{2,29}$/', $slug) !== 1) {
            throw new InvalidArgumentException('Slug must be 3-30 chars: lowercase letters and digits, starting with a letter.');
        }

        $restaurant = new self($id, trim($name), $slug, RestaurantStatus::Provisioning, null);
        $restaurant->events[] = new TenantRegistered($id, $slug);

        return $restaurant;
    }

    /** @param list<string> $provisioned */
    public static function reconstitute(string $id, string $name, string $slug, RestaurantStatus $status, ?string $failureReason, array $provisioned = []): self
    {
        return new self($id, $name, $slug, $status, $failureReason, $provisioned);
    }

    /**
     * One service finished provisioning. Idempotent per service; the restaurant becomes active
     * once every expected service has reported. Results outside `provisioning` are ignored.
     *
     * @param  list<string>  $expected
     */
    public function recordProvisioned(string $service, array $expected): void
    {
        if ($this->status !== RestaurantStatus::Provisioning) {
            return;
        }
        if (! in_array($service, $this->provisioned, true)) {
            $this->provisioned[] = $service;
        }
        if (array_diff($expected, $this->provisioned) === []) {
            $this->markProvisioned();
        }
    }

    public function markProvisioned(): void
    {
        if ($this->status === RestaurantStatus::Provisioning) {
            $this->status = RestaurantStatus::Active;
            $this->failureReason = null;
        }
    }

    public function markProvisioningFailed(string $reason): void
    {
        if ($this->status === RestaurantStatus::Provisioning) {
            $this->status = RestaurantStatus::ProvisioningFailed;
            $this->failureReason = $reason;
        }
    }

    public function retryProvisioning(): void
    {
        if ($this->status !== RestaurantStatus::ProvisioningFailed) {
            throw new DomainException('Only a failed provisioning can be retried.');
        }

        $this->status = RestaurantStatus::Provisioning;
        $this->failureReason = null;
        $this->events[] = new TenantRegistered($this->id, $this->slug);
    }

    public function suspend(): void
    {
        if ($this->status !== RestaurantStatus::Active) {
            throw new DomainException('Only an active restaurant can be suspended.');
        }

        $this->status = RestaurantStatus::Suspended;
    }

    /** @return list<object> */
    public function pullEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /** @return list<string> */
    public function provisionedServices(): array
    {
        return $this->provisioned;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function status(): RestaurantStatus
    {
        return $this->status;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }
}
