<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Infrastructure\Auth\PlatformSessions;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Http\ApiError;

final class AuthController
{
    public function login(LoginRequest $request, PlatformSessions $sessions): JsonResponse
    {
        return $this->tokens($sessions->login(
            strtolower($request->string('email')->toString()),
            $request->string('password')->toString(),
        ));
    }

    public function refresh(Request $request, PlatformSessions $sessions): JsonResponse
    {
        $request->validate(['refresh_token' => ['required', 'string', 'max:255']]);

        return $this->tokens($sessions->refresh($request->string('refresh_token')->toString()));
    }

    /** shortcut: revokes refresh tokens only; the access JWT lives out its TTL (10 min). Add a Redis denylist if that window matters. */
    public function logout(Request $request, PlatformSessions $sessions): JsonResponse
    {
        $user = $this->user($request);
        if ($user === null) {
            return ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }
        $sessions->revokeAll($user);

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
