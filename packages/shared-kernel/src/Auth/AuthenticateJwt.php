<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mestaro\Kernel\Http\ApiError;
use Symfony\Component\HttpFoundation\Response;

/** Verifies the Bearer JWT and exposes its claims as the `claims` request attribute. */
final readonly class AuthenticateJwt
{
    public function __construct(private JwtVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return ApiError::response('UNAUTHENTICATED', 'Missing credentials.', 401);
        }

        try {
            $request->attributes->set('claims', $this->verifier->verify($token));
        } catch (InvalidToken) {
            Log::notice('Access denied: invalid token.', ['method' => $request->method(), 'path' => Str::limit($request->path(), 120, '')]);

            return ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }

        return $next($request);
    }
}
