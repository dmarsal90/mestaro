<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductOptionController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\ZoneController;
use Illuminate\Support\Facades\Route;
use Mestaro\Kernel\Auth\AuthenticateJwt;
use Mestaro\Kernel\Auth\RequirePermission;
use Mestaro\Kernel\Tenancy\ResolveTenant;

// The restaurant always comes from the token (ResolveTenant); reading is open to its staff, writing needs floor.manage.
Route::middleware(['throttle:120,1', AuthenticateJwt::class, ResolveTenant::class])->group(function () {
    $manage = RequirePermission::class.':floor.manage';
    $menu = RequirePermission::class.':menu.manage';

    Route::get('zones', [ZoneController::class, 'index']);
    Route::post('zones', [ZoneController::class, 'store'])->middleware($manage);
    Route::patch('zones/{id}', [ZoneController::class, 'update'])->middleware($manage)->whereUlid('id');

    Route::get('tables', [TableController::class, 'index']);
    Route::post('tables', [TableController::class, 'store'])->middleware($manage);
    Route::patch('tables/{id}', [TableController::class, 'update'])->middleware($manage)->whereUlid('id');
    Route::post('tables/{id}/qr/rotate', [TableController::class, 'rotateQr'])->middleware($manage)->whereUlid('id');

    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store'])->middleware($menu);
    Route::patch('categories/{id}', [CategoryController::class, 'update'])->middleware($menu)->whereUlid('id');

    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store'])->middleware($menu);
    Route::patch('products/{id}', [ProductController::class, 'update'])->middleware($menu)->whereUlid('id');
    Route::delete('products/{id}', [ProductController::class, 'destroy'])->middleware($menu)->whereUlid('id');

    foreach (['variants', 'addons'] as $kind) {
        Route::post("products/{productId}/$kind", [ProductOptionController::class, 'store'])->middleware($menu)->whereUlid('productId')->defaults('kind', $kind);
        Route::patch("products/{productId}/$kind/{id}", [ProductOptionController::class, 'update'])->middleware($menu)->whereUlid(['productId', 'id'])->defaults('kind', $kind);
        Route::delete("products/{productId}/$kind/{id}", [ProductOptionController::class, 'destroy'])->middleware($menu)->whereUlid(['productId', 'id'])->defaults('kind', $kind);
    }
});
