<?php

namespace App\Domain;

/** The acceptance of an order (TRD §6.3); how each item is being prepared is a separate state. */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** @return list<self> the statuses an order may come from to reach this one */
    public function sources(): array
    {
        return match ($this) {
            self::Pending => [],
            self::Accepted, self::Rejected => [self::Pending],
            self::Cancelled => [self::Pending, self::Accepted],
        };
    }
}
