<?php

namespace App\Http\Middleware;

use App\Application\Audit\AuditTrail;
use App\Application\Auth\AccessClaims;
use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs after AuthenticateJwt. Platform administration is never tenant-scoped. */
final class RequirePlatformAdmin
{
    public function __construct(private AuditTrail $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');

        if ($claims->role !== 'platform_admin' || $claims->tenantId !== null) {
            $this->audit->record('access.denied', $claims->userId, metadata: ['reason' => 'forbidden', 'method' => $request->method(), 'path' => $request->path()]);

            return ApiError::response('FORBIDDEN', 'Platform administrator required.', 403);
        }

        return $next($request);
    }
}
