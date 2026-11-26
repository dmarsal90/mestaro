<?php

namespace App\Providers;

use App\Infrastructure\Messaging\ProjectCatalog;
use Illuminate\Support\ServiceProvider;
use Mestaro\Kernel\Messaging\EventHandler;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Projects catalog's events and hands the rest (TenantRegistered) to tenant provisioning.
        $this->app->bind(EventHandler::class, ProjectCatalog::class);
    }
}
