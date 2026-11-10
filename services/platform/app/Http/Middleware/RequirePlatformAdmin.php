<?php

namespace App\Http\Middleware;

use App\Application\Auth\AccessClaims;
use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs after AuthenticateJwt. Platform administration is never tenant-scoped. */
final class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');

        if ($claims->role !== 'platform_admin' || $claims->tenantId !== null) {
            return ApiError::response('FORBIDDEN', 'Platform administrator required.', 403);
        }

        return $next($request);
    }
}
