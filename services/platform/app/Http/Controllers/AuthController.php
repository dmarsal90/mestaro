<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Infrastructure\Auth\PlatformSessions;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Auth\TokenDenylist;
use Mestaro\Kernel\Http\ApiError;

final class AuthController
{
    public function login(LoginRequest $request, PlatformSessions $sessions): JsonResponse
    {
        return $this->tokens($sessions->login(
            strtolower($request->string('email')->toString()),
            $request->string('password')->toString(),
            $request->has('restaurant_id') ? $request->string('restaurant_id')->toString() : null,
        ));
    }

    public function refresh(Request $request, PlatformSessions $sessions): JsonResponse
    {
        $request->validate(['refresh_token' => ['required', 'string', 'max:255']]);

        return $this->tokens($sessions->refresh($request->string('refresh_token')->toString()));
    }

    /** Revokes every refresh token and the access token used for this call; other devices' access tokens expire within their TTL. */
    public function logout(Request $request, PlatformSessions $sessions, TokenDenylist $denylist): JsonResponse
    {
        $user = $this->user($request);
        if ($user === null) {
            return ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }
        $sessions->revokeAll($user);
        $denylist->revokeToken($request->attributes->get('claims'));

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user === null) {
            return ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }
        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');

        return response()->json(['data' => [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $claims->role,
            'tenant_id' => $claims->tenantId,
            'permissions' => $claims->permissions,
        ]]);
    }

    /** @param array<string, mixed>|null $tokens */
    private function tokens(?array $tokens): JsonResponse
    {
        // One generic answer for every failure so the endpoint does not reveal which accounts exist.
        return $tokens === null
            ? ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401)
            : response()->json(['data' => $tokens]);
    }

    private function user(Request $request): ?User
    {
        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');

        return User::where('public_id', $claims->userId)->where('is_active', true)->first();
    }
}
