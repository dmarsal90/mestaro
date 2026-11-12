<?php

namespace App\Http\Middleware;

use App\Application\Audit\AuditTrail;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Http\ApiError;
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
            $this->audit->record('access.denied', $claims->userId, metadata: ['reason' => 'forbidden', 'method' => $request->method(), 'path' => Str::limit($request->path(), 120, '')]);

            return ApiError::response('FORBIDDEN', 'Platform administrator required.', 403);
        }

        // Claims outlive the account for up to the JWT TTL: re-check the account on every admin call.
        $stillAdmin = User::where('public_id', $claims->userId)->where('is_active', true)->where('is_platform_admin', true)->exists();
        if (! $stillAdmin) {
            $this->audit->record('access.denied', $claims->userId, metadata: ['reason' => 'inactive_admin', 'method' => $request->method(), 'path' => Str::limit($request->path(), 120, '')]);

            return ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }

        return $next($request);
    }
}
