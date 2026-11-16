<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Auth;

use Closure;
use Illuminate\Http\Request;
use Mestaro\Kernel\Http\ApiError;
use Symfony\Component\HttpFoundation\Response;

/** After AuthenticateJwt: `RequirePermission::class.':floor.manage'` lets through only tokens that carry that permission. */
final readonly class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $claims = $request->attributes->get('claims');
        if (! $claims instanceof AccessClaims || ! in_array($permission, $claims->permissions, true)) {
            return ApiError::response('FORBIDDEN', 'You are not allowed to do this.', 403);
        }

        return $next($request);
    }
}
