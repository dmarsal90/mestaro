<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Randomizer;
use stdClass;

/** Table sessions and their participants, in the restaurant's database. */
final readonly class TableSessions
{
    /** The statuses that occupy a table (TRD §6.4). */
    public const LIVE = ['pending_approval', 'open', 'bill_requested'];

    /** No 0/O, 1/I/L: the code is read aloud and typed (spec §7.6). */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    // shortcut: fixed expiry for unapproved requests and none for open sessions (the waiter closes them); make it a setting if restaurants ask.
    private const APPROVAL_MINUTES = 15;

    public function __construct(private CatalogProjection $projection) {}

    /** The table's live session, after expiring a request nobody approved in time. */
    public function liveOn(string $tableId): ?stdClass
    {
        DB::table('table_sessions')->where('table_id', $tableId)->where('status', 'pending_approval')
            ->where('created_at', '<', now()->subMinutes(self::APPROVAL_MINUTES))
            ->update(['status' => 'expired', 'closed_at' => now(), 'updated_at' => now()]);

        return DB::table('table_sessions')->where('table_id', $tableId)->whereIn('status', self::LIVE)->first();
    }

    /** Throws UniqueConstraintViolationException if another request opened the table first. */
    public function open(string $tableId): stdClass
    {
        $settings = $this->projection->settings();
        $pending = (bool) $settings['require_waiter_approval_for_session'];
        $id = DB::table('table_sessions')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()), 'table_id' => $tableId,
            'status' => $pending ? 'pending_approval' : 'open', 'opened_at' => $pending ? null : now(),
            'join_code' => $this->newCode((int) $settings['join_code_reuse_cooldown_minutes']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('table_sessions')->find($id);
    }

    /**
     * Adds a participant and returns its credential, shown only now: the database keeps its hash. The restaurant
     * prefix only says where to look the hash up.
     */
    public function addParticipant(int $sessionId, ?string $displayName, string $tenantId): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::table('table_session_participants')->insert([
            'public_id' => strtolower((string) Str::ulid()), 'table_session_id' => $sessionId, 'display_name' => $displayName,
            'token_hash' => hash('sha256', $secret), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return "$tenantId.$secret";
    }

    /** @return array<string, mixed> what a participant may know about its session */
    public function forParticipant(stdClass $session): array
    {
        return [
            'id' => $session->public_id, 'status' => $session->status,
            'table_label' => $this->projection->find('table', $session->table_id)['label'] ?? null,
            // Not before the waiter approves: nobody else may join a request that might be refused.
            'join_code' => in_array($session->status, ['open', 'bill_requested'], true) ? $session->join_code : null,
        ];
    }

    private function newCode(int $cooldownMinutes): string
    {
        $randomizer = app(Randomizer::class);
        do {
            $code = $randomizer->getBytesFromString(self::ALPHABET, 6);
        } while (DB::table('table_sessions')->where('join_code', $code)
            ->where(fn ($q) => $q->whereIn('status', self::LIVE)->orWhere('closed_at', '>', now()->subMinutes($cooldownMinutes)))
            ->exists());

        return $code;
    }
}
