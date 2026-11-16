<?php

use App\Infrastructure\Auth\JwtKeys;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;

const ALFA = '01J00000000000000000000001';
const BETA = '01J00000000000000000000002';

beforeEach(function () {
    config(['jwt.private_key' => JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    app()->forgetInstance(JwtKeys::class);
    restaurant(ALFA, 'Alfa');
    restaurant(BETA, 'Beta');
});

/** @return array{Authorization: string} Bearer for a staff member with this role in one restaurant. */
function settingsAuth(string $role, string $restaurant = ALFA): array
{
    $user = User::factory()->create(['email' => "$role@example.com", 'password' => 'correct horse battery']);
    member($user, $restaurant, $role);
    $token = test()->postJson('/api/v1/auth/login', ['email' => "$role@example.com", 'password' => 'correct horse battery'])->json('data.access_token');

    return ['Authorization' => "Bearer $token"];
}

it('reads the defaults when nothing was configured', function () {
    $this->getJson('/api/v1/settings', settingsAuth('waiter'))->assertOk()
        ->assertJsonPath('data.currency', 'EUR')->assertJsonPath('data.auto_accept_orders', false)->assertJsonPath('data.opening_hours', null);
});

it('lets a manager change some fields and keeps the rest', function () {
    $auth = settingsAuth('manager');
    $hours = ['mon' => [['open' => '12:00', 'close' => '16:00'], ['open' => '20:00', 'close' => '23:30']]];

    $this->patchJson('/api/v1/settings', ['auto_accept_orders' => true, 'opening_hours' => $hours], $auth)->assertOk();
    $this->patchJson('/api/v1/settings', ['currency' => 'USD'], $auth)->assertOk();

    $this->getJson('/api/v1/settings', $auth)->assertOk()
        ->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.auto_accept_orders', true)->assertJsonPath('data.opening_hours', $hours);
    $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated', 'subject_id' => ALFA]);
});

it('forbids changes without restaurant.manage and tokens without a restaurant', function () {
    $this->patchJson('/api/v1/settings', ['currency' => 'USD'], settingsAuth('waiter'))->assertForbidden();
    $this->getJson('/api/v1/settings')->assertUnauthorized();

    $admin = platformAdmin(['email' => 'root@example.com', 'password' => 'correct horse battery']);
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'root@example.com', 'password' => 'correct horse battery'])->json('data.access_token');
    $this->getJson('/api/v1/settings', ['Authorization' => "Bearer $token"])->assertForbidden();
    expect($admin)->not->toBeNull();
});

it('scopes every call to the restaurant in the token and ignores one in the body', function () {
    $alfa = settingsAuth('manager');
    $this->patchJson('/api/v1/settings', ['currency' => 'USD', 'restaurant_id' => BETA], $alfa)->assertOk();

    expect(DB::table('restaurant_settings')->pluck('currency', 'restaurant_id')->all())->toBe([ALFA => 'USD']);
});

it('rejects invalid values', function (array $body) {
    $this->patchJson('/api/v1/settings', $body, settingsAuth('manager'))->assertStatus(422);
    expect(DB::table('restaurant_settings')->count())->toBe(0);
})->with([
    'currency' => [['currency' => 'euro']],
    'timezone' => [['timezone' => 'Mars/Base']],
    'locale' => [['locale' => 'ES_es']],
    'tax' => [['default_tax_bps' => 10001]],
    'alert' => [['order_alert_threshold_seconds' => 5]],
    'day key' => [['opening_hours' => ['funday' => []]]],
    'time' => [['opening_hours' => ['mon' => [['open' => '25:00', 'close' => '26:00']]]]],
    'flag' => [['auto_accept_orders' => 'maybe']],
    'big preferences' => [['preferences' => ['x' => str_repeat('a', 9000)]]],
]);
