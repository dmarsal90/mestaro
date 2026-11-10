<?php

namespace App\Http\Middleware;

use App\Application\Audit\AuditTrail;
use App\Application\Auth\InvalidToken;
use App\Http\ApiError;
use App\Infrastructure\Auth\JwtVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Verifies the Bearer JWT and exposes its claims as the `claims` request attribute. */
final class AuthenticateJwt
{
    public function __construct(private JwtVerifier $verifier, private AuditTrail $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return ApiError::response('UNAUTHENTICATED', 'Missing credentials.', 401);
        }

        try {
            $request->attributes->set('claims', $this->verifier->verify($token));
        } catch (InvalidToken) {
            $this->audit->record('access.denied', metadata: ['reason' => 'invalid_token', 'method' => $request->method(), 'path' => $request->path()]);

            return ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }

        return $next($request);
    }
}
