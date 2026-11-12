<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Auth;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mestaro\Kernel\Http\ApiError;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Verifies the Bearer JWT and exposes its claims as the `claims` request attribute. */
final readonly class AuthenticateJwt
{
    // The verifier is resolved inside handle(): building it fetches platform's JWKS, which can fail.
    public function __construct(private Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return ApiError::response('UNAUTHENTICATED', 'Missing credentials.', 401);
        }

        try {
            $verifier = $this->container->make(JwtVerifier::class);
        } catch (Throwable $e) {
            report($e);

            // Not a bad token (401) but no way to check one: fail closed without telling why.
            return ApiError::response('AUTH_UNAVAILABLE', 'Authentication is temporarily unavailable.', 503);
        }

        try {
            $request->attributes->set('claims', $verifier->verify($token));
        } catch (InvalidToken) {
            Log::notice('Access denied: invalid token.', ['method' => $request->method(), 'path' => Str::limit($request->path(), 120, '')]);

            return ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }

        return $next($request);
    }
}
