<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

enum RestaurantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case ProvisioningFailed = 'provisioning_failed';
    case Suspended = 'suspended';
}
