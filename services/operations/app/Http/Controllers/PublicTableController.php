<?php

namespace App\Http\Controllers;

use App\Application\CatalogProjection;
use App\Application\TableSessions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Mestaro\Kernel\Http\ApiError;
use Mestaro\Kernel\Tenancy\TenantContext;
use Mestaro\Kernel\Tenancy\TenantCredentials;

/**
 * What a diner can do with only the QR of a table. The QR identifies the table and authorizes nothing: the first
 * diner opens the session, everyone else needs its join code.
 */
final class PublicTableController
{
    private const QR = '/^[A-Za-z0-9_-]{43}$/';

    /** Wrong codes allowed in 10 minutes, per address at a table and for the table as a whole. */
    private const TRIES_PER_ADDRESS = 5;

    private const TRIES_PER_TABLE = 30;

    public function __construct(
        private readonly TableSessions $sessions,
        private readonly CatalogProjection $projection,
        private readonly TenantContext $context,
        private readonly TenantCredentials $credentials,
    ) {}

    public function show(string $qr): JsonResponse
    {
        return $this->atTable($qr, fn (array $table) => response()->json(['data' => [
            'table' => ['label' => $table['label']],
            'has_session' => $this->sessions->liveOn($table['id']) !== null,
        ]]));
    }

    public function open(Request $request, string $qr): JsonResponse
    {
        $data = $request->validate(['display_name' => ['nullable', 'string', 'max:40']]);

        return $this->atTable($qr, function (array $table, string $tenant) use ($data) {
            $exists = ApiError::response('SESSION_EXISTS', 'This table already has a session: ask for its code.', 409);
            if ($this->sessions->liveOn($table['id']) !== null) {
                return $exists;
            }
            try {
                [$session, $token] = DB::transaction(function () use ($table, $tenant, $data) {
                    $session = $this->sessions->open($table['id']);

                    return [$session, $this->sessions->addParticipant($session->id, $data['display_name'] ?? null, $tenant)];
                });
            } catch (UniqueConstraintViolationException) {
                return $exists;
            }

            return response()->json(['data' => ['participant_token' => $token, 'session' => $this->sessions->forParticipant($session)]], 201);
        });
    }

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_token' => ['required', 'string'], 'join_code' => ['required', 'string', 'size:6'], 'display_name' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->atTable($data['qr_token'], function (array $table, string $tenant) use ($request, $data) {
            $limits = ["join:$tenant:{$table['id']}:{$request->ip()}" => self::TRIES_PER_ADDRESS, "join:$tenant:{$table['id']}" => self::TRIES_PER_TABLE];
            // Counted before checking, so parallel guesses cannot slip past the limit; a right code gives its try back.
            foreach ($limits as $key => $max) {
                if (RateLimiter::hit($key, 600) > $max) {
                    return ApiError::response('JOIN_CODE_LOCKED', 'Too many wrong codes. Try again later.', 429)
                        ->withHeaders(['Retry-After' => RateLimiter::availableIn($key)]);
                }
            }

            $this->sessions->liveOn($table['id']);
            $session = DB::table('table_sessions')->where('table_id', $table['id'])->whereIn('status', ['open', 'bill_requested'])
                ->where('join_code', strtoupper($data['join_code']))->first();
            if ($session === null) {
                return ApiError::response('JOIN_CODE_INVALID', 'That code is not valid for this table.', 422);
            }
            foreach (array_keys($limits) as $key) {
                RateLimiter::decrement($key, 600);
            }
            $token = $this->sessions->addParticipant($session->id, $data['display_name'] ?? null, $tenant);

            return response()->json(['data' => ['participant_token' => $token, 'session' => $this->sessions->forParticipant($session)]], 201);
        });
    }

    /**
     * Finds the restaurant and table behind a QR and runs the callback inside that restaurant's database.
     *
     * @param  callable(array<string, mixed>, string): JsonResponse  $then
     */
    private function atTable(string $qr, callable $then): JsonResponse
    {
        $unknown = ApiError::response('NOT_FOUND', 'This QR is not valid. Scan the one on your table.', 404);
        $entry = preg_match(self::QR, $qr) === 1 ? DB::table('qr_tokens')->where('token_hash', hash('sha256', $qr))->first() : null;
        $password = $entry === null ? null : $this->credentials->existingPasswordFor($entry->tenant_id, 'operations');
        if ($password === null) {
            return $unknown;
        }

        $this->context->use($entry->tenant_id, $password);
        try {
            $table = $this->projection->usableTable($entry->table_id);

            return $table === null ? $unknown : $then($table, $entry->tenant_id);
        } finally {
            $this->context->clear();
        }
    }
}
