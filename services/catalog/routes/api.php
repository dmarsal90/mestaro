<?php

use App\Http\Controllers\TableController;
use App\Http\Controllers\ZoneController;
use Illuminate\Support\Facades\Route;
use Mestaro\Kernel\Auth\AuthenticateJwt;
use Mestaro\Kernel\Auth\RequirePermission;
use Mestaro\Kernel\Tenancy\ResolveTenant;

// The restaurant always comes from the token (ResolveTenant); reading is open to its staff, writing needs floor.manage.
Route::middleware(['throttle:120,1', AuthenticateJwt::class, ResolveTenant::class])->group(function () {
    $manage = RequirePermission::class.':floor.manage';

    Route::get('zones', [ZoneController::class, 'index']);
    Route::post('zones', [ZoneController::class, 'store'])->middleware($manage);
    Route::patch('zones/{id}', [ZoneController::class, 'update'])->middleware($manage)->whereUlid('id');

    Route::get('tables', [TableController::class, 'index']);
    Route::post('tables', [TableController::class, 'store'])->middleware($manage);
    Route::patch('tables/{id}', [TableController::class, 'update'])->middleware($manage)->whereUlid('id');
    Route::post('tables/{id}/qr/rotate', [TableController::class, 'rotateQr'])->middleware($manage)->whereUlid('id');
});
