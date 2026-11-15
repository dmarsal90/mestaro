<?php

declare(strict_types=1);

namespace Mestaro\Kernel;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use Mestaro\Kernel\Auth\JwksVerifierFactory;
use Mestaro\Kernel\Auth\JwtVerifier;
use Mestaro\Kernel\Auth\TokenDenylist;
use Mestaro\Kernel\Messaging\ConsumeEvents;
use Mestaro\Kernel\Messaging\RelayOutbox;
use Mestaro\Kernel\Tenancy\DatabaseTenantCredentials;
use Mestaro\Kernel\Tenancy\PostgresTenantDatabaseCreator;
use Mestaro\Kernel\Tenancy\TenantContext;
use Mestaro\Kernel\Tenancy\TenantCredentials;
use Mestaro\Kernel\Tenancy\TenantDatabaseCreator;

final class KernelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/events.php', 'events');
        $this->app->bind(TenantDatabaseCreator::class, PostgresTenantDatabaseCreator::class);
        $this->app->bind(TenantCredentials::class, DatabaseTenantCredentials::class);
        $this->app->scoped(TenantContext::class);
        // Default for services that only verify tokens; platform, which signs them, binds its own.
        $this->app->bind(JwtVerifier::class, fn ($app) => $app->make(JwksVerifierFactory::class)->make());
        // A fixed key prefix, unlike the per-service default, so every service sees the same revocations.
        // Set here, not lazily: the Redis manager copies its connection list when first resolved.
        $config = $this->app['config'];
        $config->set('database.redis.denylist', [...$config->get('database.redis.default', []), 'options' => ['prefix' => 'mestaro:denylist:']]);
        $this->app->singleton(TokenDenylist::class, fn () => new TokenDenylist(fn () => Redis::connection('denylist'), (int) $config->get('jwt.ttl', 600)));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RelayOutbox::class, ConsumeEvents::class]);
        }
    }
}
