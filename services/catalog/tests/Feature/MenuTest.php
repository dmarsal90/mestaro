<?php

declare(strict_types=1);
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function category(string $tenant = FLOOR_A, string $name = 'Entrantes'): string
{
    return test()->postJson('/api/v1/categories', ['name' => $name], staffOf($tenant))->assertCreated()->json('data.id');
}

/** @param array<string, mixed> $extra */
function product(string $category, string $tenant = FLOOR_A, array $extra = []): array
{
    return test()->postJson('/api/v1/products', ['category_id' => $category, 'name' => 'Croquetas', 'price' => 850, ...$extra], staffOf($tenant))->assertCreated()->json('data');
}

it('manages categories: unique names, deactivation, validation', function () {
    $id = category();

    $this->postJson('/api/v1/categories', ['name' => 'Entrantes'], staffOf(FLOOR_A))->assertStatus(409)->assertJsonPath('error.code', 'CATEGORY_NAME_TAKEN');
    $this->patchJson("/api/v1/categories/$id", ['name' => 'Para empezar', 'is_active' => false], staffOf(FLOOR_A))->assertOk()->assertJsonPath('data.is_active', false);
    $this->patchJson('/api/v1/categories/01JNOPE0000000000000000000', ['name' => 'X'], staffOf(FLOOR_A))->assertNotFound();
    $this->postJson('/api/v1/categories', ['name' => ''], staffOf(FLOOR_A))->assertStatus(422);
    $this->postJson('/api/v1/products', ['category_id' => $id, 'name' => 'Pan', 'price' => 100], staffOf(FLOOR_A))->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CATEGORY');
});

it('creates a product with defaults and lists it with its category', function () {
    $category = category();

    $p = product($category, extra: ['allergens' => ['gluten', 'milk'], 'ingredients' => ['bechamel'], 'available_from' => '12:00', 'available_until' => '16:30', 'destination' => 'bar']);

    expect($p)->toMatchArray(['category_id' => $category, 'price' => 850, 'destination' => 'bar', 'allergens' => ['gluten', 'milk'], 'ingredients' => ['bechamel'], 'available_from' => '12:00', 'available_until' => '16:30', 'is_available' => true, 'is_sold_out' => false, 'tax_bps' => null, 'variants' => [], 'addons' => []]);
    $this->getJson('/api/v1/products?category_id='.$category, staffOf(FLOOR_A, ['orders.view']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products?category_id='.category(FLOOR_A, 'Postres'), staffOf(FLOOR_A))->assertOk()->assertJsonCount(0, 'data');
});

it('rejects bad product input', function () {
    $category = category();
    $base = ['category_id' => $category, 'name' => 'X', 'price' => 100];

    foreach ([['price' => -1], ['price' => 1.5], ['price' => 100_000_001], ['price' => '12,50'], ['allergens' => ['glitter']], ['allergens' => ['milk', 'milk']], ['destination' => 'roof'], ['available_from' => '25:00'], ['tax_bps' => 10001], ['name' => '']] as $bad) {
        $this->postJson('/api/v1/products', [...$base, ...$bad], staffOf(FLOOR_A))->assertStatus(422);
    }
});

it('updates availability and soft-deletes, keeping other restaurants out', function () {
    $p = product(category());

    $this->patchJson("/api/v1/products/{$p['id']}", ['is_sold_out' => true, 'price' => 900, 'available_until' => null], staffOf(FLOOR_A))
        ->assertOk()->assertJsonPath('data.is_sold_out', true)->assertJsonPath('data.price', 900);
    $this->patchJson("/api/v1/products/{$p['id']}", ['price' => 1], staffOf(FLOOR_B))->assertNotFound();
    $this->getJson('/api/v1/products', staffOf(FLOOR_B))->assertJsonCount(0, 'data');

    $this->deleteJson("/api/v1/products/{$p['id']}", [], staffOf(FLOOR_A))->assertNoContent();
    $this->getJson('/api/v1/products', staffOf(FLOOR_A))->assertJsonCount(0, 'data');
    $this->deleteJson("/api/v1/products/{$p['id']}", [], staffOf(FLOOR_A))->assertNotFound();
    $this->patchJson("/api/v1/products/{$p['id']}", ['price' => 1], staffOf(FLOOR_A))->assertNotFound();
});

it('manages variants with a single default, and add-ons with limits', function () {
    $p = product(category());
    $base = "/api/v1/products/{$p['id']}";

    $small = $this->postJson("$base/variants", ['name' => 'Media', 'is_default' => true], staffOf(FLOOR_A))->assertCreated()->json('data');
    $big = $this->postJson("$base/variants", ['name' => 'Ración', 'price_delta' => 400, 'is_default' => true], staffOf(FLOOR_A))->assertCreated()->json('data');
    $this->postJson("$base/variants", ['name' => 'Ración'], staffOf(FLOOR_A))->assertStatus(409)->assertJsonPath('error.code', 'OPTION_NAME_TAKEN');
    $this->postJson("$base/variants", ['name' => 'Neg', 'price_delta' => -5], staffOf(FLOOR_A))->assertStatus(422);

    $addon = $this->postJson("$base/addons", ['name' => 'Extra queso', 'price' => 100, 'max_quantity' => 3], staffOf(FLOOR_A))->assertCreated()->json('data');
    $this->postJson("$base/addons", ['name' => 'Mucho', 'max_quantity' => 0], staffOf(FLOOR_A))->assertStatus(422);
    $this->patchJson("$base/addons/{$addon['id']}", ['is_available' => false], staffOf(FLOOR_A))->assertOk()->assertJsonPath('data.is_available', false);

    $listed = $this->getJson('/api/v1/products', staffOf(FLOOR_A))->json('data.0');
    expect(collect($listed['variants'])->where('is_default', true)->pluck('name')->all())->toBe(['Ración'])
        ->and($listed['addons'][0]['max_quantity'])->toBe(3);

    $this->patchJson("$base/variants/{$small['id']}", ['is_default' => true], staffOf(FLOOR_A))->assertOk();
    expect(collect($this->getJson('/api/v1/products', staffOf(FLOOR_A))->json('data.0.variants'))->where('is_default', true)->pluck('name')->all())->toBe(['Media']);

    $this->deleteJson("$base/variants/{$big['id']}", [], staffOf(FLOOR_A))->assertNoContent();
    $this->deleteJson("$base/variants/{$big['id']}", [], staffOf(FLOOR_A))->assertNotFound();
    $this->postJson('/api/v1/products/01JNOPE0000000000000000000/variants', ['name' => 'X'], staffOf(FLOOR_A))->assertNotFound();
});

it('does not reach options through another product or restaurant', function () {
    $category = category();
    $a = product($category);
    $b = product($category, extra: ['name' => 'Tortilla']);
    $variant = $this->postJson("/api/v1/products/{$a['id']}/variants", ['name' => 'V'], staffOf(FLOOR_A))->json('data.id');

    $this->patchJson("/api/v1/products/{$b['id']}/variants/$variant", ['name' => 'Hack'], staffOf(FLOOR_A))->assertNotFound();
    $this->deleteJson("/api/v1/products/{$a['id']}/variants/$variant", [], staffOf(FLOOR_B))->assertNotFound();
});

it('lets any staff read the menu but only menu.manage write it', function () {
    $category = category();
    $p = product($category);
    $waiter = staffOf(FLOOR_A, ['orders.view']);

    $this->getJson('/api/v1/categories', $waiter)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products', $waiter)->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/categories', ['name' => 'X'], $waiter)->assertForbidden();
    $this->postJson('/api/v1/products', ['category_id' => $category, 'name' => 'X', 'price' => 1], $waiter)->assertForbidden();
    $this->patchJson("/api/v1/products/{$p['id']}", ['price' => 1], $waiter)->assertForbidden();
    $this->deleteJson("/api/v1/products/{$p['id']}", [], $waiter)->assertForbidden();
    $this->postJson("/api/v1/products/{$p['id']}/addons", ['name' => 'X'], $waiter)->assertForbidden();
    $this->postJson('/api/v1/zones', ['name' => 'Z'], staffOf(FLOOR_A, ['menu.manage']))->assertForbidden();
});

it('stores a product photo by its real type, serves it publicly and replaces or removes it', function () {
    Storage::fake('local');
    $p = product(category());

    $url = $this->post("/api/v1/products/{$p['id']}/image", ['image' => pngUpload()], staffOf(FLOOR_A) + ['Accept' => 'application/json'])->assertCreated()->json('data.image_url');
    expect($url)->toMatch('#^/api/v1/media/'.FLOOR_A.'/[0-9a-f]{40}\.png$#')
        ->and($this->getJson('/api/v1/products', staffOf(FLOOR_A))->json('data.0.image_url'))->toBe($url);

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(str_replace(FLOOR_A, FLOOR_B, $url))->assertNotFound();

    $second = $this->post("/api/v1/products/{$p['id']}/image", ['image' => pngUpload()], staffOf(FLOOR_A) + ['Accept' => 'application/json'])->json('data.image_url');
    $this->get($url)->assertNotFound();
    $this->get($second)->assertOk();

    $this->deleteJson("/api/v1/products/{$p['id']}/image", [], staffOf(FLOOR_A))->assertNoContent();
    $this->get($second)->assertNotFound();
    expect($this->getJson('/api/v1/products', staffOf(FLOOR_A))->json('data.0.image_url'))->toBeNull();
});

it('refuses files that are not images whatever their name says, and unauthorised uploads', function () {
    Storage::fake('local');
    $p = product(category());
    $headers = staffOf(FLOOR_A) + ['Accept' => 'application/json'];

    $script = UploadedFile::fake()->createWithContent('shell.png', '<?php echo 1;');
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $big = UploadedFile::fake()->create('big.png', 3000, 'image/png');

    foreach ([$script, $svg, $big] as $bad) {
        $this->post("/api/v1/products/{$p['id']}/image", ['image' => $bad], $headers)->assertStatus(422);
    }
    $this->post("/api/v1/products/{$p['id']}/image", [], $headers)->assertStatus(422);
    $this->post("/api/v1/products/{$p['id']}/image", ['image' => pngUpload()], staffOf(FLOOR_A, ['orders.view']) + ['Accept' => 'application/json'])->assertForbidden();
    $this->post('/api/v1/products/01JNOPE0000000000000000000/image', ['image' => pngUpload()], $headers)->assertNotFound();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('does not serve anything for names it did not generate', function () {
    $this->get('/api/v1/media/'.FLOOR_A.'/..%2F..%2F.env')->assertNotFound();
    $this->get('/api/v1/media/'.FLOOR_A.'/not-a-generated-name.png')->assertNotFound();
});
