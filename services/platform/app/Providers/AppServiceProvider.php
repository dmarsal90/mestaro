<?php

namespace App\Providers;

use App\Application\EventPublisher;
use App\Domain\Tenancy\RestaurantRepository;
use App\Infrastructure\Messaging\OutboxEventPublisher;
use App\Infrastructure\Tenancy\DatabaseRestaurantRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RestaurantRepository::class, DatabaseRestaurantRepository::class);
        $this->app->bind(EventPublisher::class, OutboxEventPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
