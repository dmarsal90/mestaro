<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RestaurantAdminController;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\RequirePlatformAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware(AuthenticateJwt::class);
    Route::get('me', [AuthController::class, 'me'])->middleware(AuthenticateJwt::class);
});

Route::prefix('admin')->middleware([AuthenticateJwt::class, RequirePlatformAdmin::class, 'throttle:60,1'])->group(function () {
    Route::post('restaurants', [RestaurantAdminController::class, 'store']);
    Route::get('restaurants/{id}', [RestaurantAdminController::class, 'show']);
    Route::post('restaurants/{id}/suspend', [RestaurantAdminController::class, 'suspend']);
    Route::post('restaurants/{id}/retry-provisioning', [RestaurantAdminController::class, 'retryProvisioning']);
});
