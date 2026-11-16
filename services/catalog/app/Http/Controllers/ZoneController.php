<?php

namespace App\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mestaro\Kernel\Http\ApiError;

/** Zones of the restaurant in the token (its own database): no restaurant filter is needed or possible. */
final class ZoneController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('zones')->orderBy('sort_order')->orderBy('name')->get()->map($this->present(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'sort_order' => ['sometimes', 'integer', 'between:0,65535']]);

        try {
            $id = (string) Str::ulid();
            DB::transaction(fn () => DB::table('zones')->insert(['public_id' => strtolower($id), 'name' => $data['name'], 'sort_order' => $data['sort_order'] ?? 0, 'created_at' => now(), 'updated_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            return ApiError::response('ZONE_NAME_TAKEN', 'A zone with that name already exists.', 409);
        }

        return response()->json(['data' => $this->present(DB::table('zones')->where('public_id', strtolower($id))->first())], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:80'], 'sort_order' => ['sometimes', 'integer', 'between:0,65535'], 'is_active' => ['sometimes', 'boolean']]);
        $id = strtolower($id);
        if (! DB::table('zones')->where('public_id', $id)->exists()) {
            return ApiError::response('NOT_FOUND', 'Zone not found.', 404);
        }

        try {
            DB::transaction(fn () => DB::table('zones')->where('public_id', $id)->update([...$data, 'updated_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            return ApiError::response('ZONE_NAME_TAKEN', 'A zone with that name already exists.', 409);
        }

        return response()->json(['data' => $this->present(DB::table('zones')->where('public_id', $id)->first())]);
    }

    /** @return array<string, mixed> */
    private function present(object $z): array
    {
        return ['id' => $z->public_id, 'name' => $z->name, 'sort_order' => $z->sort_order, 'is_active' => (bool) $z->is_active];
    }
}
