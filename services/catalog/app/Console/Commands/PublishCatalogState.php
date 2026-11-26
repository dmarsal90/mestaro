<?php

namespace App\Console\Commands;

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\ZoneController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Mestaro\Kernel\Tenancy\TenantContext;
use Mestaro\Kernel\Tenancy\TenantCredentials;
use Throwable;

/** Publishes every row again: the initial load of a new projection, or its repair. Safe to repeat. */
final class PublishCatalogState extends Command
{
    private const SOURCES = [
        'categories' => CategoryController::class,
        'zones' => ZoneController::class,
        'products' => ProductController::class, // soft-deleted ones go out as deletions
        'tables' => TableController::class,
    ];

    protected $signature = 'catalog:publish-state';

    protected $description = 'Publish the current catalog and floor of every restaurant';

    public function handle(TenantContext $context, TenantCredentials $credentials): int
    {
        $failed = 0;
        foreach (DB::table('tenant_credentials')->where('service', 'catalog')->pluck('restaurant_id') as $tenant) {
            try {
                $context->use($tenant, $credentials->existingPasswordFor($tenant, 'catalog'));
                foreach (self::SOURCES as $table => $source) {
                    $publisher = app($source);
                    foreach (DB::table($table)->orderBy('id')->pluck('public_id') as $id) {
                        // The row lock keeps this state ordered with any write happening at the same time.
                        DB::transaction(function () use ($table, $id, $publisher) {
                            DB::table($table)->where('public_id', $id)->lockForUpdate()->first();
                            $publisher->publish($id);
                        });
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("Tenant $tenant failed.");
            } finally {
                $context->clear();
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
