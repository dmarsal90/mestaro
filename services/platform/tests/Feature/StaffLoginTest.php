<?php

use App\Infrastructure\Auth\JwtKeys;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['jwt.private_key' => JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    app()->forgetInstance(JwtKeys::class);
});

const STAFF_PASSWORD = 'correct horse battery';

function staff(): User
{
    return User::factory()->create(['email' => 'staff@example.com', 'password' => STAFF_PASSWORD]);
}

function staffLogin(array $extra = [])
{
    return test()->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => STAFF_PASSWORD, ...$extra]);
}

it('logs in staff with a single restaurant and scopes the token to it', function () {
    member(staff(), restaurant('01J00000000000000000000001', 'Alfa'), 'waiter');

    $token = staffLogin()->assertOk()->json('data.access_token');

    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer $token"])->assertOk()
        ->assertJsonPath('data.role', 'waiter')
        ->assertJsonPath('data.tenant_id', '01J00000000000000000000001')
        ->assertJsonPath('data.permissions', ['calls.view', 'orders.accept', 'orders.view']);
    $this->getJson('/api/v1/admin/restaurants/01J00000000000000000000001', ['Authorization' => "Bearer $token"])->assertForbidden();
});

it('asks staff with several restaurants to choose and rejects a restaurant they do not belong to', function () {
    $user = staff();
    member($user, restaurant('01J00000000000000000000001', 'Alfa'), 'waiter');
    member($user, restaurant('01J00000000000000000000002', 'Beta'), 'manager');
    restaurant('01J00000000000000000000003', 'Gamma');

    staffLogin()->assertOk()->assertJsonPath('data.restaurants.0.name', 'Alfa')->assertJsonMissingPath('data.access_token');
    staffLogin(['restaurant_id' => '01J00000000000000000000002'])->assertOk()->assertJsonMissingPath('data.restaurants');
    staffLogin(['restaurant_id' => '01J00000000000000000000003'])->assertUnauthorized();
});

it('ignores inactive memberships and restaurants that are not active', function () {
    $user = staff();
    member($user, restaurant('01J00000000000000000000001', 'Alfa'), 'waiter', active: false);
    member($user, restaurant('01J00000000000000000000002', 'Beta', 'suspended'), 'waiter');

    staffLogin()->assertUnauthorized();
});

it('re-reads the membership on refresh and keeps the restaurant', function () {
    $user = staff();
    member($user, restaurant('01J00000000000000000000001', 'Alfa'), 'waiter');
    $refresh = staffLogin()->json('data.refresh_token');

    $new = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertOk()->json('data');
    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$new['access_token']}"])->assertJsonPath('data.tenant_id', '01J00000000000000000000001');

    DB::table('restaurant_user')->where('user_id', $user->id)->update(['is_active' => false]);
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $new['refresh_token']])->assertUnauthorized();
});
