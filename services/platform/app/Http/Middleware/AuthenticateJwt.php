<?php

namespace App\Http\Middleware;

use App\Application\Audit\AuditTrail;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mestaro\Kernel\Auth\InvalidToken;
use Mestaro\Kernel\Auth\JwtVerifier;
use Mestaro\Kernel\Auth\TokenDenylist;
use Mestaro\Kernel\Http\ApiError;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Verifies the Bearer JWT and exposes its claims as the `claims` request attribute. */
final class AuthenticateJwt
{
    public function __construct(private JwtVerifier $verifier, private TokenDenylist $denylist, private AuditTrail $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return ApiError::response('UNAUTHENTICATED', 'Missing credentials.', 401);
        }

        try {
            $claims = $this->verifier->verify($token);
            $revoked = $this->denylist->isRevoked($claims);
        } catch (InvalidToken) {
            $revoked = null;
        } catch (Throwable $e) {
            report($e);

            return ApiError::response('AUTH_UNAVAILABLE', 'Authentication is temporarily unavailable.', 503); // fail closed
        }

        if ($revoked !== false) {
            $this->audit->record('access.denied', metadata: ['reason' => $revoked === null ? 'invalid_token' : 'revoked_token', 'method' => $request->method(), 'path' => Str::limit($request->path(), 120, '')]);

            return ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }
        $request->attributes->set('claims', $claims);

        return $next($request);
    }
}
