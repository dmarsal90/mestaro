<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Http\ApiError;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after AuthenticateJwt. The tenant comes only from the verified token, never from the body, the URL or a
 * header, and it fails closed: no token claims, no tenant, or a tenant never provisioned here never reaches the route.
 */
final readonly class ResolveTenant
{
    public function __construct(private TenantCredentials $credentials, private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $claims = $request->attributes->get('claims');
        if (! $claims instanceof AccessClaims) {
            return ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }
        if ($claims->tenantId === null) {
            return ApiError::response('FORBIDDEN', 'A restaurant context is required.', 403);
        }

        $password = $this->credentials->existingPasswordFor($claims->tenantId, config('kernel.service'));
        if ($password === null) {
            return ApiError::response('NOT_FOUND', 'Resource not found.', 404);
        }

        $this->context->use($claims->tenantId, $password);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
