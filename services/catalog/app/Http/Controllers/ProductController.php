<?php

namespace App\Http\Controllers;

use App\Http\CatalogEvents;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Mestaro\Kernel\Http\ApiError;
use Mestaro\Kernel\Tenancy\TenantContext;

/** Products with their variants and add-ons. Soft-deleted products disappear from every read but stay for past orders. */
final class ProductController
{
    /** The 14 allergens of EU Regulation 1169/2011. */
    private const ALLERGENS = ['gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk', 'nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs'];

    private const DESTINATIONS = ['kitchen', 'bar', 'other'];

    private const MAX_PRICE = 100_000_000; // 1,000,000.00 in cents: rejects typos and overflow

    public function __construct(private readonly CatalogEvents $events) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->products()->orderBy('c.sort_order')->orderBy('p.sort_order')->orderBy('p.name');
        if ($request->filled('category_id') && is_string($request->query('category_id'))) {
            $query->where('c.public_id', strtolower($request->query('category_id')));
        }

        return response()->json(['data' => $this->presentAll($query->get())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $category = DB::table('categories')->where('public_id', strtolower($data['category_id']))->where('is_active', true)->value('id');
        if ($category === null) {
            return ApiError::response('INVALID_CATEGORY', 'The category does not exist or is inactive.', 422);
        }
        $id = strtolower((string) Str::ulid());

        $product = DB::transaction(function () use ($data, $id, $category) {
            DB::table('products')->insert([...$this->columns($data), 'public_id' => $id, 'category_id' => $category, 'created_at' => now(), 'updated_at' => now()]);

            return $this->publish($id);
        });

        return response()->json(['data' => $product], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $id = strtolower($id);
        if (! DB::table('products')->where('public_id', $id)->whereNull('deleted_at')->exists()) {
            return ApiError::response('NOT_FOUND', 'Product not found.', 404);
        }
        $columns = $this->columns($data);
        if (isset($data['category_id'])) {
            $category = DB::table('categories')->where('public_id', strtolower($data['category_id']))->where('is_active', true)->value('id');
            if ($category === null) {
                return ApiError::response('INVALID_CATEGORY', 'The category does not exist or is inactive.', 422);
            }
            $columns['category_id'] = $category;
        }

        $product = DB::transaction(function () use ($id, $columns) {
            DB::table('products')->where('public_id', $id)->update([...$columns, 'updated_at' => now()]);

            return $this->publish($id);
        });

        return response()->json(['data' => $product]);
    }

    public function destroy(string $id): JsonResponse
    {
        $id = strtolower($id);
        $deleted = DB::transaction(function () use ($id) {
            $deleted = DB::table('products')->where('public_id', $id)->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
            if ($deleted !== 0) {
                $this->publish($id);
            }

            return $deleted;
        });

        return $deleted === 0 ? ApiError::response('NOT_FOUND', 'Product not found.', 404) : response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $time = ['nullable', 'date_format:H:i'];

        return [
            'category_id' => [$req, 'string', 'ulid'],
            'name' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => [$req, 'integer', 'between:0,'.self::MAX_PRICE],
            'tax_bps' => ['nullable', 'integer', 'between:0,10000'],
            'destination' => ['sometimes', Rule::in(self::DESTINATIONS)],
            'allergens' => ['sometimes', 'array', 'max:14'],
            'allergens.*' => ['string', 'distinct', Rule::in(self::ALLERGENS)],
            'ingredients' => ['sometimes', 'array', 'max:40'],
            'ingredients.*' => ['string', 'max:60'],
            'available_from' => $time,
            'available_until' => $time,
            'is_available' => ['sometimes', 'boolean'],
            'is_sold_out' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
        ];
    }

    /**
     * Request fields as columns: JSON lists encoded, the category (a public id) left to the caller.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        unset($data['category_id']);
        foreach (['allergens', 'ingredients'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = json_encode(array_values($data[$list]));
            }
        }

        return $data;
    }

    /**
     * Records the product with its options as they are now. Call inside the transaction, after writing the
     * product row: that row's lock keeps the events of one product in order.
     *
     * @return array<string, mixed>
     */
    public function publish(string $publicId): array
    {
        $product = $this->presentAll($this->products()->where('p.public_id', $publicId)->get())[0] ?? ['id' => $publicId, 'deleted' => true];
        $this->events->changed('product', $product);

        return $product;
    }

    private function products(): Builder
    {
        return DB::table('products as p')->join('categories as c', 'c.id', '=', 'p.category_id')->whereNull('p.deleted_at')
            ->select('p.*', 'c.public_id as category_public_id');
    }

    /**
     * @param  iterable<int, \stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function presentAll(iterable $rows): array
    {
        /** @var Collection<int, \stdClass> $rows */
        $rows = collect($rows);
        $ids = $rows->pluck('id')->all();
        $variants = DB::table('product_variants')->whereIn('product_id', $ids)->orderBy('id')->get()->groupBy('product_id');
        $addons = DB::table('product_addons')->whereIn('product_id', $ids)->orderBy('id')->get()->groupBy('product_id');

        return $rows->map(fn ($p) => [
            'id' => $p->public_id, 'category_id' => $p->category_public_id, 'name' => $p->name, 'description' => $p->description,
            'image_url' => $p->image_path === null ? null : MediaController::url(app(TenantContext::class)->tenantId(), $p->image_path),
            'price' => (int) $p->price, 'tax_bps' => $p->tax_bps, 'destination' => $p->destination,
            'allergens' => json_decode($p->allergens, true), 'ingredients' => json_decode($p->ingredients, true),
            'available_from' => $p->available_from ? substr($p->available_from, 0, 5) : null,
            'available_until' => $p->available_until ? substr($p->available_until, 0, 5) : null,
            'is_available' => (bool) $p->is_available, 'is_sold_out' => (bool) $p->is_sold_out, 'sort_order' => $p->sort_order,
            'variants' => ($variants[$p->id] ?? collect())->map(fn ($v) => ['id' => $v->public_id, 'name' => $v->name, 'price_delta' => (int) $v->price_delta, 'is_default' => (bool) $v->is_default, 'is_available' => (bool) $v->is_available])->values()->all(),
            'addons' => ($addons[$p->id] ?? collect())->map(fn ($a) => ['id' => $a->public_id, 'name' => $a->name, 'price' => (int) $a->price, 'max_quantity' => $a->max_quantity, 'is_available' => (bool) $a->is_available])->values()->all(),
        ])->values()->all();
    }
}
