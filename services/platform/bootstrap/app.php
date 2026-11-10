<?php

use App\Http\ApiError;
use App\Http\Controllers\JwksController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Outside the web group: no session or cookies on a public, cacheable endpoint.
        then: fn () => Route::get('/.well-known/jwks.json', JwksController::class),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (ValidationException $e, Request $request) => $request->is('api/*')
            ? ApiError::response('VALIDATION_FAILED', 'Invalid input.', 422, $e->errors())
            : null);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
