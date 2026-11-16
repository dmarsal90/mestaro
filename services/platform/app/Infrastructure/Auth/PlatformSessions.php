<?php

namespace App\Infrastructure\Auth;

use App\Application\Audit\AuditTrail;
use App\Application\Auth\TokenIssuer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Mestaro\Kernel\Auth\AccessClaims;

/**
 * Login, refresh and revocation for platform administrators and restaurant staff.
 * Access tokens are short JWTs; refresh tokens are Sanctum tokens, hashed at rest and revocable.
 * A staff token is scoped to one restaurant; the refresh token remembers which one, and role and
 * permissions are re-read from the database on every refresh so a revoked membership stops at the next one.
 */
final class PlatformSessions
{
    private const REFRESH = 'refresh';

    private const RESTAURANT_ABILITY = 'restaurant:';

    private const MAX_REFRESH_TOKENS = 10;

    private static ?string $dummyHash = null;

    public function __construct(private TokenIssuer $issuer, private AuditTrail $audit) {}

    /**
     * Tokens, or `['restaurants' => [...]]` when a staff member belongs to several restaurants and must pick one.
     *
     * @return array<string, mixed>|null
     */
    public function login(string $email, #[\SensitiveParameter] string $password, ?string $restaurantId = null): ?array
    {
        $user = User::where('email', $email)->first();

        // Always hash-compare so an unknown email costs the same as a wrong password.
        $valid = Hash::check($password, $user->password ?? (self::$dummyHash ??= Hash::make(bin2hex(random_bytes(8)))));

        $memberships = $valid && $user !== null && $user->is_active ? $this->memberships($user) : [];
        $asAdmin = $valid && $user !== null && $user->is_active && $user->is_platform_admin && $restaurantId === null;

        if (! $asAdmin) {
            if ($restaurantId === null && count($memberships) > 1) {
                $this->audit->record('auth.login_selection', $user->public_id);

                return ['restaurants' => array_map(fn ($m) => ['id' => $m->id, 'name' => $m->name], $memberships)];
            }
            $membership = $restaurantId === null ? ($memberships[0] ?? null) : $this->find($memberships, $restaurantId);
            if ($membership === null) {
                $this->audit->record('auth.login_failed', $user?->public_id, metadata: ['email' => $email]);

                return null;
            }
        }
        $this->audit->record('auth.login', $user->public_id, metadata: ['restaurant_id' => $membership->id ?? null]);

        return $this->issue($user, $membership ?? null);
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}|null */
    public function refresh(#[\SensitiveParameter] string $plain): ?array
    {
        $token = PersonalAccessToken::findToken($plain);
        $user = $token?->tokenable;

        if ($token === null || ! $token->can(self::REFRESH) || $token->expires_at?->isPast()
            || ! $user instanceof User || ! $user->is_active) {
            $this->audit->record('auth.refresh_failed', $user instanceof User ? $user->public_id : null);

            return null;
        }

        $restaurantId = $this->restaurantOf($token);
        $membership = $restaurantId === null ? null : $this->find($this->memberships($user), $restaurantId);
        if (($restaurantId === null && ! $user->is_platform_admin) || ($restaurantId !== null && $membership === null)) {
            $token->delete();
            $this->audit->record('auth.refresh_failed', $user->public_id, metadata: ['reason' => 'membership_gone']);

            return null;
        }

        // Rotation: a refresh token works once; losing the delete race means it was already used.
        if ($token->newQuery()->whereKey($token->getKey())->delete() !== 1) {
            $user->tokens()->delete(); // two clients held the same token: treat the session as compromised
            $this->audit->record('auth.refresh_failed', $user->public_id, metadata: ['reason' => 'token_reused']);

            return null;
        }
        $this->audit->record('auth.refresh', $user->public_id);

        return $this->issue($user, $membership);
    }

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
        $this->audit->record('auth.logout', $user->public_id);
    }

    /** @return list<object{id: string, name: string, role_id: int, role: string}> Active memberships in active restaurants. */
    private function memberships(User $user): array
    {
        return DB::table('restaurant_user as ru')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->join('restaurants as s', 's.id', '=', 'ru.restaurant_id')
            ->where('ru.user_id', $user->id)->where('ru.is_active', true)->where('s.status', 'active')
            ->orderBy('s.name')->get(['s.id', 's.name', 'r.id as role_id', 'r.key as role'])->all();
    }

    /** @param list<object> $memberships */
    private function find(array $memberships, string $restaurantId): ?object
    {
        foreach ($memberships as $m) {
            if ($m->id === $restaurantId) {
                return $m;
            }
        }

        return null;
    }

    private function restaurantOf(PersonalAccessToken $token): ?string
    {
        foreach ($token->abilities as $ability) {
            if (str_starts_with($ability, self::RESTAURANT_ABILITY)) {
                return substr($ability, strlen(self::RESTAURANT_ABILITY));
            }
        }

        return null;
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string} */
    private function issue(User $user, ?object $membership): array
    {
        $abilities = $membership === null ? [self::REFRESH] : [self::REFRESH, self::RESTAURANT_ABILITY.$membership->id];
        $refresh = $user->createToken(self::REFRESH, $abilities, now()->addSeconds(config('jwt.refresh_ttl')));
        // A login flood cannot grow the table without bound: keep only the newest tokens.
        $user->tokens()->whereNotIn('id', $user->tokens()->latest('id')->limit(self::MAX_REFRESH_TOKENS)->pluck('id'))->delete();

        return [
            'access_token' => $this->issuer->issue($membership === null
                ? new AccessClaims($user->public_id, null, 'platform_admin', [])
                : new AccessClaims($user->public_id, $membership->id, $membership->role, DB::table('permission_role as pr')
                    ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                    ->where('pr.role_id', $membership->role_id)->orderBy('p.key')->pluck('p.key')->all())),
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl'),
            'refresh_token' => $refresh->plainTextToken,
        ];
    }
}
