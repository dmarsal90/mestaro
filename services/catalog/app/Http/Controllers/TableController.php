<?php

namespace App\Http\Controllers;

use App\Http\CatalogEvents;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Http\ApiError;

/** Tables of the restaurant in the token. The QR token is only shown to people who can manage the floor. */
final class TableController
{
    public function __construct(private readonly CatalogEvents $events) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->tables()->orderBy('z.sort_order')->orderBy('t.label')->get();

        return response()->json(['data' => $rows->map(fn ($t) => $this->present($t, $request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'zone_id' => ['required', 'string', 'ulid'], 'label' => ['required', 'string', 'max:40'], 'capacity' => ['required', 'integer', 'between:1,65535'],
        ]);
        $zone = $this->activeZone($data['zone_id']);
        if ($zone === null) {
            return ApiError::response('INVALID_ZONE', 'The zone does not exist or is inactive.', 422);
        }

        $id = strtolower((string) Str::ulid());
        try {
            $table = DB::transaction(function () use ($id, $zone, $data, $request) {
                DB::table('tables')->insert([
                    'public_id' => $id, 'zone_id' => $zone, 'label' => $data['label'], 'capacity' => $data['capacity'],
                    'qr_token' => self::newQrToken(), 'created_at' => now(), 'updated_at' => now(),
                ]);

                return $this->publish($id, $request);
            });
        } catch (UniqueConstraintViolationException) {
            return ApiError::response('TABLE_LABEL_TAKEN', 'A table with that label already exists.', 409);
        }

        return response()->json(['data' => $table], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'zone_id' => ['sometimes', 'string', 'ulid'], 'label' => ['sometimes', 'string', 'max:40'],
            'capacity' => ['sometimes', 'integer', 'between:1,65535'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $id = strtolower($id);
        if (! DB::table('tables')->where('public_id', $id)->exists()) {
            return ApiError::response('NOT_FOUND', 'Table not found.', 404);
        }
        if (isset($data['zone_id'])) {
            $zone = $this->activeZone($data['zone_id']);
            if ($zone === null) {
                return ApiError::response('INVALID_ZONE', 'The zone does not exist or is inactive.', 422);
            }
            $data['zone_id'] = $zone;
        }

        try {
            $table = DB::transaction(function () use ($id, $data, $request) {
                DB::table('tables')->where('public_id', $id)->update([...$data, 'updated_at' => now()]);

                return $this->publish($id, $request);
            });
        } catch (UniqueConstraintViolationException) {
            return ApiError::response('TABLE_LABEL_TAKEN', 'A table with that label already exists.', 409);
        }

        return response()->json(['data' => $table]);
    }

    /** The old QR stops working at once: only the new token resolves the table. */
    public function rotateQr(Request $request, string $id): JsonResponse
    {
        $id = strtolower($id);
        $table = DB::transaction(function () use ($id, $request) {
            $changed = DB::table('tables')->where('public_id', $id)->update(['qr_token' => self::newQrToken(), 'qr_rotated_at' => now(), 'updated_at' => now()]);

            return $changed === 0 ? null : $this->publish($id, $request);
        });

        return $table === null ? ApiError::response('NOT_FOUND', 'Table not found.', 404) : response()->json(['data' => $table]);
    }

    /**
     * Records the change and returns the row as the API shows it. Every caller has floor.manage, so it carries the QR token.
     *
     * @return array<string, mixed>
     */
    private function publish(string $publicId, Request $request): array
    {
        $table = $this->present($this->tables()->where('t.public_id', $publicId)->first(), $request);
        $this->events->changed('table', $table);

        return $table;
    }

    /** 32 random bytes as 43 base64url characters (RF-TBL-02). */
    private static function newQrToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function activeZone(string $publicId): ?int
    {
        return DB::table('zones')->where('public_id', strtolower($publicId))->where('is_active', true)->value('id');
    }

    private function tables(): Builder
    {
        return DB::table('tables as t')->join('zones as z', 'z.id', '=', 't.zone_id')
            ->select('t.public_id', 't.label', 't.capacity', 't.qr_token', 't.qr_rotated_at', 't.is_active', 'z.public_id as zone_id');
    }

    /** @return array<string, mixed> */
    private function present(object $t, Request $request): array
    {
        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');
        $row = ['id' => $t->public_id, 'zone_id' => $t->zone_id, 'label' => $t->label, 'capacity' => $t->capacity, 'is_active' => (bool) $t->is_active];

        return in_array('floor.manage', $claims->permissions, true) ? [...$row, 'qr_token' => $t->qr_token, 'qr_rotated_at' => $t->qr_rotated_at] : $row;
    }
}
