<?php

use App\Application\Auth\TokenIssuer;
use App\Infrastructure\Auth\JwtKeys;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Mestaro\Kernel\Auth\AccessClaims;
use Symfony\Component\Yaml\Yaml;

/** The contract lives in docs/openapi.yaml; the container sees it at /var/docs, CI and the host at ../../docs. */
function openApi(): array
{
    static $spec;
    foreach ([base_path('../../docs/openapi.yaml'), '/var/docs/openapi.yaml'] as $path) {
        if (is_file($path)) {
            return $spec ??= Yaml::parseFile($path);
        }
    }
    throw new RuntimeException('docs/openapi.yaml not found.');
}

/** Minimal JSON Schema subset used by our spec: $ref, oneOf, type (incl. null), enum, pattern, required, properties, items. */
function schemaErrors(mixed $value, array $schema, string $at = '$'): array
{
    if (isset($schema['$ref'])) {
        $node = openApi();
        foreach (explode('/', ltrim($schema['$ref'], '#/')) as $part) {
            $node = $node[$part];
        }

        return schemaErrors($value, $node, $at);
    }
    if (isset($schema['oneOf'])) {
        $matches = array_filter($schema['oneOf'], fn ($s) => schemaErrors($value, $s, $at) === []);

        return count($matches) === 1 ? [] : ["$at matches ".count($matches).' of the oneOf schemas'];
    }

    $errors = [];
    $types = (array) ($schema['type'] ?? []);
    $actual = match (true) {
        $value === null => 'null',
        is_bool($value) => 'boolean',
        is_int($value) => 'integer',
        is_string($value) => 'string',
        is_array($value) => array_is_list($value) ? 'array' : 'object',
        default => 'other',
    };
    // An empty PHP array decodes from `{}` or `[]` alike.
    if ($types !== [] && ! in_array($actual, $types, true) && ! ($value === [] && array_intersect($types, ['array', 'object']))) {
        return ["$at is $actual, expected ".implode('|', $types)];
    }
    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        $errors[] = "$at is not one of the allowed values";
    }
    if (isset($schema['pattern']) && is_string($value) && preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/', $value) !== 1) {
        $errors[] = "$at does not match {$schema['pattern']}";
    }
    if (is_array($value) && ! array_is_list($value)) {
        foreach ($schema['required'] ?? [] as $key) {
            array_key_exists($key, $value) || $errors[] = "$at.$key is missing";
        }
        foreach ($schema['properties'] ?? [] as $key => $sub) {
            array_key_exists($key, $value) && $errors = [...$errors, ...schemaErrors($value[$key], $sub, "$at.$key")];
        }
        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) as $key) {
                $errors[] = "$at.$key is not documented";
            }
        }
    }
    if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
        foreach ($value as $i => $item) {
            $errors = [...$errors, ...schemaErrors($item, $schema['items'], "$at[$i]")];
        }
    }

    return $errors;
}

/** Fails unless the response body fits the schema documented for this operation and status. */
function assertDocumented(string $method, string $path, $response): void
{
    $operation = openApi()['paths'][$path][strtolower($method)] ?? null;
    expect($operation)->not->toBeNull("$method $path is not in openapi.yaml");
    $status = (string) $response->getStatusCode();
    $declared = $operation['responses'][$status] ?? null;
    expect($declared)->not->toBeNull("$method $path answers $status, which openapi.yaml does not list");

    $schema = ($declared['content']['application/json']['schema'] ?? null)
        ?? (isset($declared['$ref']) ? openApi()['components']['responses'][basename($declared['$ref'])]['content']['application/json']['schema'] : null);
    if ($schema !== null) {
        expect(schemaErrors($response->json(), $schema))->toBe([], "$method $path $status");
    }
}

beforeEach(function () {
    config(['jwt.private_key' => JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    app()->forgetInstance(JwtKeys::class);
});

it('documents every API route and nothing that does not exist', function () {
    $actual = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/'))
        ->flatMap(fn (LaravelRoute $r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS']))
            ->map(fn ($m) => $m.' /'.substr($r->uri(), strlen('api/v1/'))))
        ->sort()->values()->all();

    $documented = collect(openApi()['paths'])->flatMap(fn ($ops, $path) => collect($ops)
        ->keys()->filter(fn ($k) => in_array($k, ['get', 'post', 'put', 'patch', 'delete']))
        ->map(fn ($m) => strtoupper($m).' '.$path))->sort()->values()->all();

    expect($documented)->toBe($actual);
});

it('answers the documented shapes', function () {
    $password = 'correct horse battery';
    $admin = platformAdmin(['email' => 'root@example.com', 'password' => $password]);
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'root@example.com', 'password' => $password]);
    assertDocumented('POST', '/auth/login', $login);
    $auth = ['Authorization' => 'Bearer '.$login->json('data.access_token')];

    assertDocumented('GET', '/auth/me', $this->getJson('/api/v1/auth/me', $auth));
    assertDocumented('POST', '/auth/login', $this->postJson('/api/v1/auth/login', ['email' => 'root@example.com', 'password' => 'wrong password!!']));
    assertDocumented('POST', '/auth/refresh', $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login->json('data.refresh_token')]));
    assertDocumented('POST', '/auth/forgot-password', $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']));
    assertDocumented('POST', '/auth/reset-password', $this->postJson('/api/v1/auth/reset-password', ['email' => 'nobody@example.com', 'token' => 'x', 'password' => 'a brand new password', 'password_confirmation' => 'a brand new password']));

    $created = $this->postJson('/api/v1/admin/restaurants', ['name' => 'Alfa', 'slug' => 'alfa'], $auth);
    assertDocumented('POST', '/admin/restaurants', $created);
    $id = $created->json('data.id');
    assertDocumented('POST', '/admin/restaurants', $this->postJson('/api/v1/admin/restaurants', ['name' => 'Alfa', 'slug' => 'alfa'], $auth));
    assertDocumented('POST', '/admin/restaurants', $this->postJson('/api/v1/admin/restaurants', ['slug' => 'Bad'], $auth));
    assertDocumented('GET', '/admin/restaurants/{id}', $this->getJson("/api/v1/admin/restaurants/$id", $auth));
    assertDocumented('GET', '/admin/restaurants/{id}', $this->getJson('/api/v1/admin/restaurants/01J99999999999999999999999', $auth));
    assertDocumented('POST', '/admin/restaurants/{id}/suspend', $this->postJson("/api/v1/admin/restaurants/$id/suspend", [], $auth));
    assertDocumented('POST', '/admin/restaurants/{id}/retry-provisioning', $this->postJson("/api/v1/admin/restaurants/$id/retry-provisioning", [], $auth));

    // Staff and settings, as the administrator of the restaurant.
    $user = User::factory()->create(['email' => 'boss@example.com']);
    member($user, $id, 'restaurant_admin');
    $permissions = DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')->join('roles as r', 'r.id', '=', 'pr.role_id')
        ->whereNull('r.restaurant_id')->where('r.key', 'restaurant_admin')->pluck('p.key')->all();
    $boss = ['Authorization' => 'Bearer '.app(TokenIssuer::class)->issue(new AccessClaims($user->public_id, $id, 'restaurant_admin', $permissions))];
    $hours = ['mon' => [['open' => '12:00', 'close' => '16:00']]];

    assertDocumented('GET', '/settings', $this->getJson('/api/v1/settings', $boss));
    assertDocumented('PATCH', '/settings', $this->patchJson('/api/v1/settings', ['opening_hours' => $hours, 'preferences' => ['theme' => 'dark']], $boss));
    assertDocumented('PATCH', '/settings', $this->patchJson('/api/v1/settings', ['currency' => 'euro'], $boss));
    assertDocumented('GET', '/staff', $this->getJson('/api/v1/staff', $boss));
    assertDocumented('POST', '/staff', $this->postJson('/api/v1/staff', ['name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'waiter'], $boss));
    // The mailed token is not stored, so the pending invitation is swapped for one with a known token.
    DB::table('staff_invitations')->where('email', 'ana@example.com')->update(['token_hash' => hash('sha256', 'known-token')]);
    assertDocumented('POST', '/auth/accept-invitation', $this->postJson('/api/v1/auth/accept-invitation', ['token' => 'bad', 'password' => 'a brand new password']));
    assertDocumented('POST', '/auth/accept-invitation', $this->postJson('/api/v1/auth/accept-invitation', ['token' => 'known-token', 'password' => 'a brand new password']));
    assertDocumented('POST', '/staff', $this->postJson('/api/v1/staff', ['name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'waiter'], $boss));
    $ana = User::where('email', 'ana@example.com')->firstOrFail();
    assertDocumented('PATCH', '/staff/{id}', $this->patchJson('/api/v1/staff/'.$ana->public_id, ['role' => 'bar'], $boss));
    assertDocumented('PATCH', '/staff/{id}', $this->patchJson('/api/v1/staff/'.$user->public_id, ['role' => 'bar'], $boss));
    assertDocumented('POST', '/auth/logout', $this->postJson('/api/v1/auth/logout', [], $auth));
    expect($admin)->not->toBeNull();
});
