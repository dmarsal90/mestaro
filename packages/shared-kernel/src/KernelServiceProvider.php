<?php

declare(strict_types=1);

namespace Mestaro\Kernel;

use Illuminate\Support\ServiceProvider;
use Mestaro\Kernel\Auth\JwksVerifierFactory;
use Mestaro\Kernel\Auth\JwtVerifier;
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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RelayOutbox::class, ConsumeEvents::class]);
        }
    }
}
