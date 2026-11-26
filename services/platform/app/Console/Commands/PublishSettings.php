<?php

namespace App\Console\Commands;

use App\Infrastructure\Tenancy\RestaurantSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Announces every stored configuration again: the initial load of a new projection, or its repair. Safe to repeat. */
final class PublishSettings extends Command
{
    protected $signature = 'platform:publish-settings';

    protected $description = 'Publish the current settings of every restaurant that changed them';

    public function handle(RestaurantSettings $settings): int
    {
        foreach (DB::table('restaurant_settings')->pluck('restaurant_id') as $restaurantId) {
            $settings->update($restaurantId, []);
        }

        return self::SUCCESS;
    }
}
