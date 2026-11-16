<?php

namespace App\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mestaro\Kernel\Http\ApiError;

/** Menu categories of the restaurant in the token. Deactivated, never deleted: products keep pointing at them. */
final class CategoryController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('categories')->orderBy('sort_order')->orderBy('name')->get()->map($this->present(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:500'], 'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
        ]);
        $id = strtolower((string) Str::ulid());

        try {
            DB::transaction(fn () => DB::table('categories')->insert(['public_id' => $id, ...$data, 'created_at' => now(), 'updated_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            return ApiError::response('CATEGORY_NAME_TAKEN', 'A category with that name already exists.', 409);
        }

        return response()->json(['data' => $this->present(DB::table('categories')->where('public_id', $id)->first())], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $id = strtolower($id);
        if (! DB::table('categories')->where('public_id', $id)->exists()) {
            return ApiError::response('NOT_FOUND', 'Category not found.', 404);
        }

        try {
            DB::transaction(fn () => DB::table('categories')->where('public_id', $id)->update([...$data, 'updated_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            return ApiError::response('CATEGORY_NAME_TAKEN', 'A category with that name already exists.', 409);
        }

        return response()->json(['data' => $this->present(DB::table('categories')->where('public_id', $id)->first())]);
    }

    /** @return array<string, mixed> */
    private function present(object $c): array
    {
        return ['id' => $c->public_id, 'name' => $c->name, 'description' => $c->description, 'sort_order' => $c->sort_order, 'is_active' => (bool) $c->is_active];
    }
}
