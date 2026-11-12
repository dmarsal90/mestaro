<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Mestaro\Kernel\Messaging\EventHandler;
use Mestaro\Kernel\Tenancy\ProvisionTenantOnRegistered;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Catalog's only reaction to events so far: provision its own database for each new restaurant.
        $this->app->bind(EventHandler::class, ProvisionTenantOnRegistered::class);
    }
}
