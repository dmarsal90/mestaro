<?php

use App\Application\Auth\TokenIssuer;
use App\Infrastructure\Auth\JwtKeys;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Testing\OpenApiContract;

/** Fails unless the response body fits the schema documented for this operation and status. */
function assertDocumented(string $method, string $path, $response): void
{
    expect(OpenApiContract::check($method, $path, $response->getStatusCode(), (string) $response->getContent()))->toBeNull();
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

    expect(OpenApiContract::operations('platform'))->toBe($actual);
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
