<?php

declare(strict_types=1);

use Illuminate\Http\Testing\File;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mestaro\Kernel\Testing\OpenApiContract;

/** Fails unless the response fits what docs/openapi.yaml documents for this operation and status. */
function documented(string $method, string $path, $response): void
{
    expect(OpenApiContract::check($method, $path, $response->getStatusCode(), (string) $response->getContent()))->toBeNull();
}

it('documents every catalog route and nothing that does not exist', function () {
    $actual = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/') && ! str_contains($r->uri(), '_probe'))
        ->flatMap(fn (LaravelRoute $r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS']))
            ->map(fn ($m) => $m.' /'.substr($r->uri(), strlen('api/v1/'))))
        ->sort()->values()->all();

    expect(OpenApiContract::operations('catalog'))->toBe($actual);
});

it('answers the documented shapes', function () {
    Storage::fake('local');
    $h = staffOf(FLOOR_A);
    $none = staffOf(FLOOR_A, ['orders.view']);

    documented('GET', '/zones', $this->getJson('/api/v1/zones', $h));
    $zone = $this->postJson('/api/v1/zones', ['name' => 'Terraza'], $h);
    documented('POST', '/zones', $zone);
    documented('POST', '/zones', $this->postJson('/api/v1/zones', ['name' => 'Terraza'], $h));
    documented('POST', '/zones', $this->postJson('/api/v1/zones', ['name' => ''], $h));
    documented('POST', '/zones', $this->postJson('/api/v1/zones', ['name' => 'X'], $none));
    $zoneId = $zone->json('data.id');
    documented('PATCH', '/zones/{id}', $this->patchJson("/api/v1/zones/$zoneId", ['sort_order' => 3], $h));
    documented('PATCH', '/zones/{id}', $this->patchJson('/api/v1/zones/01JNOPE0000000000000000000', ['sort_order' => 3], $h));

    $table = $this->postJson('/api/v1/tables', ['zone_id' => $zoneId, 'label' => 'M1', 'capacity' => 4], $h);
    documented('POST', '/tables', $table);
    documented('POST', '/tables', $this->postJson('/api/v1/tables', ['zone_id' => $zoneId, 'label' => 'M1', 'capacity' => 4], $h));
    documented('POST', '/tables', $this->postJson('/api/v1/tables', ['zone_id' => '01JNOPE0000000000000000000', 'label' => 'M2', 'capacity' => 4], $h));
    documented('GET', '/tables', $this->getJson('/api/v1/tables', $h));
    documented('GET', '/tables', $this->getJson('/api/v1/tables', $none));
    $tableId = $table->json('data.id');
    documented('PATCH', '/tables/{id}', $this->patchJson("/api/v1/tables/$tableId", ['capacity' => 6], $h));
    documented('POST', '/tables/{id}/qr/rotate', $this->postJson("/api/v1/tables/$tableId/qr/rotate", [], $h));
    documented('POST', '/tables/{id}/qr/rotate', $this->postJson('/api/v1/tables/01JNOPE0000000000000000000/qr/rotate', [], $h));

    $category = $this->postJson('/api/v1/categories', ['name' => 'Entrantes', 'description' => 'Para empezar'], $h);
    documented('POST', '/categories', $category);
    documented('POST', '/categories', $this->postJson('/api/v1/categories', ['name' => 'Entrantes'], $h));
    documented('GET', '/categories', $this->getJson('/api/v1/categories', $none));
    $categoryId = $category->json('data.id');
    documented('PATCH', '/categories/{id}', $this->patchJson("/api/v1/categories/$categoryId", ['sort_order' => 1], $h));

    $product = $this->postJson('/api/v1/products', ['category_id' => $categoryId, 'name' => 'Croquetas', 'price' => 850, 'allergens' => ['gluten'], 'available_from' => '12:00'], $h);
    documented('POST', '/products', $product);
    documented('POST', '/products', $this->postJson('/api/v1/products', ['category_id' => $categoryId, 'name' => 'X', 'price' => -1], $h));
    $productId = $product->json('data.id');
    documented('PATCH', '/products/{id}', $this->patchJson("/api/v1/products/$productId", ['is_sold_out' => true], $h));

    $variant = $this->postJson("/api/v1/products/$productId/variants", ['name' => 'Ración', 'price_delta' => 300, 'is_default' => true], $h);
    documented('POST', '/products/{productId}/variants', $variant);
    documented('POST', '/products/{productId}/variants', $this->postJson("/api/v1/products/$productId/variants", ['name' => 'Ración'], $h));
    documented('PATCH', '/products/{productId}/variants/{id}', $this->patchJson("/api/v1/products/$productId/variants/".$variant->json('data.id'), ['is_available' => false], $h));
    $addon = $this->postJson("/api/v1/products/$productId/addons", ['name' => 'Queso', 'price' => 100, 'max_quantity' => 2], $h);
    documented('POST', '/products/{productId}/addons', $addon);
    documented('PATCH', '/products/{productId}/addons/{id}', $this->patchJson("/api/v1/products/$productId/addons/".$addon->json('data.id'), ['max_quantity' => 3], $h));
    documented('GET', '/products', $this->getJson('/api/v1/products?category_id='.$categoryId, $none));

    $png = tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $upload = $this->post("/api/v1/products/$productId/image", ['image' => new File('p.png', fopen($png, 'r'))], $h + ['Accept' => 'application/json']);
    documented('POST', '/products/{id}/image', $upload);
    documented('POST', '/products/{id}/image', $this->post("/api/v1/products/$productId/image", [], $h + ['Accept' => 'application/json']));
    $url = $upload->json('data.image_url');
    $media = $this->get($url);
    expect($media->getStatusCode())->toBe(200)->and(OpenApiContract::check('GET', '/media/{tenant}/{file}', 200, ''))->toBeNull();
    documented('GET', '/media/{tenant}/{file}', $this->getJson('/api/v1/media/'.FLOOR_A.'/'.str_repeat('0', 40).'.png'));
    documented('DELETE', '/products/{id}/image', $this->deleteJson("/api/v1/products/$productId/image", [], $h));
    documented('DELETE', '/products/{productId}/addons/{id}', $this->deleteJson("/api/v1/products/$productId/addons/".$addon->json('data.id'), [], $h));
    documented('DELETE', '/products/{id}', $this->deleteJson("/api/v1/products/$productId", [], $h));
    documented('DELETE', '/products/{id}', $this->deleteJson("/api/v1/products/$productId", [], $h));
});
