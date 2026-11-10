<?php

namespace App\Infrastructure\Auth;

use App\Application\Auth\AccessClaims;
use App\Application\Auth\TokenIssuer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Login, refresh and revocation for platform administrators.
 * Access tokens are short JWTs; refresh tokens are Sanctum tokens, hashed at rest and revocable.
 * Restaurant staff cannot log in yet: they need memberships and roles (not built).
 */
final class PlatformSessions
{
    private const REFRESH = 'refresh';

    private static ?string $dummyHash = null;

    public function __construct(private TokenIssuer $issuer) {}

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}|null */
    public function login(string $email, #[\SensitiveParameter] string $password): ?array
    {
        $user = User::where('email', $email)->first();

        // Always hash-compare so an unknown email costs the same as a wrong password.
        $valid = Hash::check($password, $user->password ?? (self::$dummyHash ??= Hash::make(bin2hex(random_bytes(8)))));

        return $valid && $user !== null && $this->canLogin($user) ? $this->issue($user) : null;
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}|null */
    public function refresh(#[\SensitiveParameter] string $plain): ?array
    {
        $token = PersonalAccessToken::findToken($plain);
        $user = $token?->tokenable;

        if ($token === null || ! $token->can(self::REFRESH) || $token->expires_at?->isPast()
            || ! $user instanceof User || ! $this->canLogin($user)) {
            return null;
        }

        // Rotation: a refresh token works once; losing the delete race means it was already used.
        if ($token->newQuery()->whereKey($token->getKey())->delete() !== 1) {
            return null;
        }

        return $this->issue($user);
    }

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }

    private function canLogin(User $user): bool
    {
        return $user->is_active && $user->is_platform_admin;
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string} */
    private function issue(User $user): array
    {
        $refresh = $user->createToken(self::REFRESH, [self::REFRESH], now()->addSeconds(config('jwt.refresh_ttl')));

        return [
            'access_token' => $this->issuer->issue(new AccessClaims($user->public_id, null, 'platform_admin', [])),
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl'),
            'refresh_token' => $refresh->plainTextToken,
        ];
    }
}
