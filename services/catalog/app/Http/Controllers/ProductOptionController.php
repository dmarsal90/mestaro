<?php

namespace App\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mestaro\Kernel\Http\ApiError;

/** Variants (price supplement, one default) and add-ons (extra with a maximum quantity) of a product; the route's `kind` picks the table. */
final class ProductOptionController
{
    private const MAX_PRICE = 100_000_000;

    public function store(Request $request, string $productId, string $kind): JsonResponse
    {
        $product = DB::table('products')->where('public_id', strtolower($productId))->whereNull('deleted_at')->value('id');
        if ($product === null) {
            return ApiError::response('NOT_FOUND', 'Product not found.', 404);
        }
        $data = $request->validate($this->rules($kind, true));
        $id = strtolower((string) Str::ulid());

        try {
            DB::transaction(function () use ($kind, $product, $id, $data) {
                $this->clearOtherDefaults($kind, $product, null, $data);
                DB::table($this->table($kind))->insert([...$data, 'public_id' => $id, 'product_id' => $product, 'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (UniqueConstraintViolationException) {
            return ApiError::response('OPTION_NAME_TAKEN', 'This product already has one with that name.', 409);
        }

        return response()->json(['data' => $this->present($kind, DB::table($this->table($kind))->where('public_id', $id)->first())], 201);
    }

    public function update(Request $request, string $productId, string $id, string $kind): JsonResponse
    {
        $row = $this->find($kind, $productId, $id);
        if ($row === null) {
            return ApiError::response('NOT_FOUND', 'Not found.', 404);
        }
        $data = $request->validate($this->rules($kind, false));

        try {
            DB::transaction(function () use ($kind, $row, $data) {
                $this->clearOtherDefaults($kind, $row->product_id, $row->id, $data);
                DB::table($this->table($kind))->where('id', $row->id)->update([...$data, 'updated_at' => now()]);
            });
        } catch (UniqueConstraintViolationException) {
            return ApiError::response('OPTION_NAME_TAKEN', 'This product already has one with that name.', 409);
        }

        return response()->json(['data' => $this->present($kind, DB::table($this->table($kind))->where('id', $row->id)->first())]);
    }

    public function destroy(string $productId, string $id, string $kind): JsonResponse
    {
        $row = $this->find($kind, $productId, $id);
        if ($row === null) {
            return ApiError::response('NOT_FOUND', 'Not found.', 404);
        }
        DB::table($this->table($kind))->where('id', $row->id)->delete();

        return response()->json(null, 204);
    }

    private function find(string $kind, string $productId, string $id): ?object
    {
        return DB::table($this->table($kind).' as o')->join('products as p', 'p.id', '=', 'o.product_id')
            ->where('p.public_id', strtolower($productId))->whereNull('p.deleted_at')->where('o.public_id', strtolower($id))->select('o.*')->first();
    }

    /**
     * Only one default variant per product: making one default demotes the rest in the same transaction.
     *
     * @param  array<string, mixed>  $data
     */
    private function clearOtherDefaults(string $kind, int $product, ?int $keep, array $data): void
    {
        if ($kind === 'variants' && ($data['is_default'] ?? false)) {
            DB::table('product_variants')->where('product_id', $product)->when($keep, fn ($q) => $q->where('id', '!=', $keep))->update(['is_default' => false]);
        }
    }

    private function table(string $kind): string
    {
        return $kind === 'variants' ? 'product_variants' : 'product_addons';
    }

    /** @return array<string, mixed> */
    private function rules(string $kind, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $kind === 'variants'
            ? ['name' => [$req, 'string', 'max:80'], 'price_delta' => ['sometimes', 'integer', 'between:0,'.self::MAX_PRICE], 'is_default' => ['sometimes', 'boolean'], 'is_available' => ['sometimes', 'boolean']]
            : ['name' => [$req, 'string', 'max:80'], 'price' => ['sometimes', 'integer', 'between:0,'.self::MAX_PRICE], 'max_quantity' => ['sometimes', 'integer', 'between:1,100'], 'is_available' => ['sometimes', 'boolean']];
    }

    /** @return array<string, mixed> */
    private function present(string $kind, object $o): array
    {
        return $kind === 'variants'
            ? ['id' => $o->public_id, 'name' => $o->name, 'price_delta' => (int) $o->price_delta, 'is_default' => (bool) $o->is_default, 'is_available' => (bool) $o->is_available]
            : ['id' => $o->public_id, 'name' => $o->name, 'price' => (int) $o->price, 'max_quantity' => $o->max_quantity, 'is_available' => (bool) $o->is_available];
    }
}
