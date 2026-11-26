<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\PublicTableController;
use App\Http\Controllers\SessionController;
use App\Http\Middleware\AuthenticateParticipant;
use Illuminate\Support\Facades\Route;
use Mestaro\Kernel\Auth\AuthenticateJwt;
use Mestaro\Kernel\Auth\RequirePermission;
use Mestaro\Kernel\Tenancy\ResolveTenant;

// Staff routes take the restaurant from the token (ResolveTenant); diner routes from the table's QR or their own credential.

// Public. Diners of one restaurant usually share its address, hence the generous limits (wrong codes are limited apart).
Route::get('public/tables/{qr}', [PublicTableController::class, 'show'])->middleware('throttle:120,1,qr');
Route::post('public/tables/{qr}/sessions', [PublicTableController::class, 'open'])->middleware('throttle:30,1,open');
Route::post('public/sessions/join', [PublicTableController::class, 'join'])->middleware('throttle:60,1,join');

Route::middleware(['throttle:600,1,session', AuthenticateParticipant::class])->group(function () {
    Route::get('session', [SessionController::class, 'mine']);
    Route::get('session/menu', [OrderController::class, 'menu']);
    Route::get('session/orders', [OrderController::class, 'mine']);
    Route::post('orders', [OrderController::class, 'store']);
});

Route::middleware(['throttle:300,1,staff', AuthenticateJwt::class, ResolveTenant::class])->group(function () {
    $accept = RequirePermission::class.':orders.accept';

    Route::get('sessions', [SessionController::class, 'index'])->middleware(RequirePermission::class.':orders.view');
    Route::post('sessions/{id}/approve', [SessionController::class, 'approve'])->middleware($accept)->whereUlid('id');
    Route::post('sessions/{id}/close', [SessionController::class, 'close'])->middleware($accept)->whereUlid('id');

    Route::get('orders', [OrderController::class, 'index'])->middleware(RequirePermission::class.':orders.view');
    Route::get('orders/{id}', [OrderController::class, 'show'])->middleware(RequirePermission::class.':orders.view')->whereUlid('id');
    foreach (['accept' => 'accepted', 'reject' => 'rejected', 'cancel' => 'cancelled'] as $action => $status) {
        Route::post("orders/{id}/$action", [OrderController::class, 'decide'])->middleware($accept)->whereUlid('id')->defaults('to', $status);
    }
});
