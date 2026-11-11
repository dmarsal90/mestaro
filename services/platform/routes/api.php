<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RestaurantAdminController;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\RequirePlatformAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:login');
    // Throttle first: a bad token costs a signature check and an audit row, so it must be rate limited.
    Route::post('logout', [AuthController::class, 'logout'])->middleware(['throttle:60,1', AuthenticateJwt::class]);
    Route::get('me', [AuthController::class, 'me'])->middleware(['throttle:60,1', AuthenticateJwt::class]);
});

Route::prefix('admin')->middleware(['throttle:60,1', AuthenticateJwt::class, RequirePlatformAdmin::class])->group(function () {
    Route::post('restaurants', [RestaurantAdminController::class, 'store']);
    Route::get('restaurants/{id}', [RestaurantAdminController::class, 'show'])->whereUlid('id');
    Route::post('restaurants/{id}/suspend', [RestaurantAdminController::class, 'suspend'])->whereUlid('id');
    Route::post('restaurants/{id}/retry-provisioning', [RestaurantAdminController::class, 'retryProvisioning'])->whereUlid('id');
});
