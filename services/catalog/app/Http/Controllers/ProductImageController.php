<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Http\ApiError;

/**
 * One photo per product. The type is decided by the file's content (finfo), never by its name or the client's
 * header; the stored name is random and the extension comes from the detected type.
 * shortcut: the file is stored as uploaded (no re-encoding: the image extension is not installed); add a re-encode step when it is.
 */
final class ProductImageController
{
    public function __construct(private readonly ProductController $products) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $product = DB::table('products')->where('public_id', strtolower($id))->whereNull('deleted_at')->first();
        if ($product === null) {
            return ApiError::response('NOT_FOUND', 'Product not found.', 404);
        }
        $request->validate(['image' => ['required', 'file', 'max:2048', 'mimes:jpg,png,webp', 'dimensions:max_width=4000,max_height=4000']]);

        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');
        $file = $request->file('image');
        $name = bin2hex(random_bytes(20)).'.'.($file->guessExtension() === 'jpeg' ? 'jpg' : $file->guessExtension());
        Storage::disk('local')->putFileAs('menu/'.$claims->tenantId, $file, $name);

        $this->setImage($product, $name);
        $this->forget($claims->tenantId, $product->image_path);

        return response()->json(['data' => ['image_url' => MediaController::url($claims->tenantId, $name)]], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $product = DB::table('products')->where('public_id', strtolower($id))->whereNull('deleted_at')->first();
        if ($product === null) {
            return ApiError::response('NOT_FOUND', 'Product not found.', 404);
        }
        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');
        $this->setImage($product, null);
        $this->forget($claims->tenantId, $product->image_path);

        return response()->json(null, 204);
    }

    private function setImage(object $product, ?string $name): void
    {
        DB::transaction(function () use ($product, $name) {
            DB::table('products')->where('id', $product->id)->update(['image_path' => $name, 'updated_at' => now()]);
            $this->products->publish($product->public_id);
        });
    }

    private function forget(string $tenant, ?string $name): void
    {
        if ($name !== null) {
            Storage::disk('local')->delete("menu/$tenant/$name");
        }
    }
}
