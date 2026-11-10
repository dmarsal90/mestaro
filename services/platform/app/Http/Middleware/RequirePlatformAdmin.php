<?php

namespace App\Http\Middleware;

use App\Application\Auth\InvalidToken;
use App\Http\ApiError;
use App\Infrastructure\Auth\JwtVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePlatformAdmin
{
    public function __construct(private JwtVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return ApiError::response('UNAUTHENTICATED', 'Missing credentials.', 401);
        }

        try {
            $claims = $this->verifier->verify($token);
        } catch (InvalidToken) {
            return ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }

        // Platform administration is never tenant-scoped.
        if ($claims->role !== 'platform_admin' || $claims->tenantId !== null) {
            return ApiError::response('FORBIDDEN', 'Platform administrator required.', 403);
        }

        return $next($request);
    }
}
