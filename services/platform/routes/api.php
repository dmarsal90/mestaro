<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\RestaurantAdminController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\RequireTenant;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:login');
    Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:login');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:login');
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

Route::prefix('settings')->middleware(['throttle:60,1', AuthenticateJwt::class])->group(function () {
    Route::get('/', [SettingsController::class, 'show'])->middleware(RequireTenant::class);
    Route::patch('/', [SettingsController::class, 'update'])->middleware(RequireTenant::class.':restaurant.manage');
});

Route::prefix('staff')->middleware(['throttle:60,1', AuthenticateJwt::class, RequireTenant::class.':users.manage'])->group(function () {
    Route::get('/', [StaffController::class, 'index']);
    Route::post('/', [StaffController::class, 'store']);
    Route::patch('{id}', [StaffController::class, 'update'])->whereUlid('id');
});
