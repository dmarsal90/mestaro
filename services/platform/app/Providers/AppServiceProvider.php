<?php

namespace App\Providers;

use App\Application\Auth\TokenIssuer;
use App\Application\EventPublisher;
use App\Domain\Tenancy\RestaurantRepository;
use App\Infrastructure\Auth\JwtIssuer;
use App\Infrastructure\Auth\JwtKeys;
use App\Infrastructure\Messaging\OutboxEventPublisher;
use App\Infrastructure\Tenancy\DatabaseRestaurantRepository;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RestaurantRepository::class, DatabaseRestaurantRepository::class);
        $this->app->bind(EventPublisher::class, OutboxEventPublisher::class);

        $this->app->singleton(JwtKeys::class, fn () => new JwtKeys(
            config('jwt.private_key') ?: throw new RuntimeException('JWT_PRIVATE_KEY is not set.'),
        ));
        $this->app->bind(TokenIssuer::class, fn ($app) => new JwtIssuer(
            $app->make(JwtKeys::class), config('jwt.issuer'), config('jwt.audience'), config('jwt.ttl'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
