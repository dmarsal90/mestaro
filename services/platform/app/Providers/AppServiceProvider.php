<?php

namespace App\Providers;

use App\Application\Audit\AuditTrail;
use App\Application\Auth\TokenIssuer;
use App\Application\EventPublisher;
use App\Application\Tenancy\TenantDatabaseCreator;
use App\Domain\Tenancy\RestaurantRepository;
use App\Infrastructure\Audit\DatabaseAuditTrail;
use App\Infrastructure\Auth\JwtIssuer;
use App\Infrastructure\Auth\JwtKeys;
use App\Infrastructure\Auth\JwtVerifier;
use App\Infrastructure\Messaging\OutboxEventPublisher;
use App\Infrastructure\Tenancy\DatabaseRestaurantRepository;
use App\Infrastructure\Tenancy\PostgresTenantDatabaseCreator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->bind(AuditTrail::class, DatabaseAuditTrail::class);
        $this->app->bind(EventPublisher::class, OutboxEventPublisher::class);
        $this->app->bind(TenantDatabaseCreator::class, PostgresTenantDatabaseCreator::class);

        $this->app->singleton(JwtKeys::class, fn () => new JwtKeys(
            config('jwt.private_key') ?: throw new RuntimeException('JWT_PRIVATE_KEY is not set.'),
        ));
        $this->app->bind(JwtVerifier::class, fn ($app) => new JwtVerifier(
            $app->make(JwtKeys::class)->jwks(), config('jwt.issuer'), config('jwt.audience'),
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
        // Per account+IP stops password guessing; the per-IP cap stops spraying many accounts.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower(is_string($email = $request->input('email')) ? $email : '').'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
