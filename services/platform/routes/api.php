<?php

use App\Http\Controllers\RestaurantAdminController;
use App\Http\Middleware\RequirePlatformAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware([RequirePlatformAdmin::class, 'throttle:60,1'])->group(function () {
    Route::post('restaurants', [RestaurantAdminController::class, 'store']);
    Route::get('restaurants/{id}', [RestaurantAdminController::class, 'show']);
    Route::post('restaurants/{id}/suspend', [RestaurantAdminController::class, 'suspend']);
    Route::post('restaurants/{id}/retry-provisioning', [RestaurantAdminController::class, 'retryProvisioning']);
});
