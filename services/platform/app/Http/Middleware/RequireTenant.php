<?php

namespace App\Http\Middleware;

use App\Application\Audit\AuditTrail;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Http\ApiError;
use Symfony\Component\HttpFoundation\Response;

/** Runs after AuthenticateJwt: the token must belong to a restaurant and, if given, hold the permission (`require.tenant:users.manage`). */
final class RequireTenant
{
    public function __construct(private AuditTrail $audit) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');

        if ($claims->tenantId === null || ($permission !== null && ! in_array($permission, $claims->permissions, true))) {
            $this->audit->record('access.denied', $claims->userId, metadata: ['reason' => 'forbidden', 'permission' => $permission, 'method' => $request->method(), 'path' => Str::limit($request->path(), 120, '')]);

            return ApiError::response('FORBIDDEN', 'You are not allowed to do this.', 403);
        }

        return $next($request);
    }
}
