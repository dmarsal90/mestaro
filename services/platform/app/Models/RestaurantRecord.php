<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of `restaurants` for the admin panel. Every change goes through the application services
 * (RegisterRestaurant, SuspendRestaurant, RetryProvisioning) so the aggregate's rules and the audit trail apply.
 */
final class RestaurantRecord extends Model
{
    protected $table = 'restaurants';

    public $incrementing = false;

    protected $keyType = 'string';
}
