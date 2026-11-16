<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Auth;

use Closure;
use Illuminate\Contracts\Redis\Connection;

/**
 * Revoked access tokens, shared by every service through one Redis prefix.
 * A single token is blocked by its `jti`; all of a user's tokens by a cutoff time. Entries live only as long
 * as a token could still be valid, so the list stays small.
 *
 * shortcut: the cutoff compares whole seconds, so a token issued in the same second as the revocation survives
 * (it still expires with its TTL). Use millisecond claims if that window ever matters.
 */
final class TokenDenylist
{
    private ?Connection $redis = null;

    /** @param Closure(): Connection $connect Called on first use, so a Redis outage surfaces where callers can fail closed. */
    public function __construct(private Closure $connect, private int $ttlSeconds) {}

    private function redis(): Connection
    {
        return $this->redis ??= ($this->connect)();
    }

    public function isRevoked(AccessClaims $claims): bool
    {
        if ($this->redis()->exists("jti:{$claims->jti}") > 0) {
            return true;
        }
        // Account-wide cutoff, plus one per restaurant so an administrator can only end sessions inside their own.
        foreach (["user:{$claims->userId}", "user:{$claims->userId}:{$claims->tenantId}"] as $key) {
            $cutoff = $this->redis()->get($key);
            if ($cutoff !== null && $claims->issuedAt < (int) $cutoff) {
                return true;
            }
        }

        return false;
    }

    public function revokeToken(AccessClaims $claims): void
    {
        $this->redis()->setex("jti:{$claims->jti}", max(1, $claims->expiresAt - time()), '1');
    }

    /** Every token of the user issued before now stops working; with a restaurant, only those scoped to it. */
    public function revokeUser(string $userId, ?string $tenantId = null): void
    {
        $this->redis()->setex($tenantId === null ? "user:{$userId}" : "user:{$userId}:{$tenantId}", $this->ttlSeconds, (string) time());
    }
}
