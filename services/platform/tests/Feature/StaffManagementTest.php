<?php

use App\Application\Auth\TokenIssuer;
use App\Infrastructure\Auth\JwtKeys;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mestaro\Kernel\Auth\AccessClaims;

const SA = '01J00000000000000000000001';
const SB = '01J00000000000000000000002';

beforeEach(function () {
    config(['jwt.private_key' => JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    app()->forgetInstance(JwtKeys::class);
    restaurant(SA, 'Alfa');
    restaurant(SB, 'Beta');
});

/** @return array{0: User, 1: array{Authorization: string}} */
function staffMember(string $role, string $restaurant = SA, ?string $email = null): array
{
    $email ??= "$role-$restaurant@example.com";
    $user = User::factory()->create(['email' => $email, 'password' => 'correct horse battery']);
    member($user, $restaurant, $role);
    // Signed directly: logging in over HTTP for every fixture would trip the login rate limit.
    $permissions = DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')
        ->join('roles as r', 'r.id', '=', 'pr.role_id')->whereNull('r.restaurant_id')->where('r.key', $role)->pluck('p.key')->all();
    $token = app(TokenIssuer::class)->issue(new AccessClaims($user->public_id, $restaurant, $role, $permissions));

    return [$user, ['Authorization' => "Bearer $token"]];
}

it('lists only the staff of the restaurant in the token', function () {
    [, $admin] = staffMember('restaurant_admin');
    staffMember('waiter');
    staffMember('waiter', SB);

    $emails = collect($this->getJson('/api/v1/staff', $admin)->assertOk()->json('data'))->pluck('email')->all();

    expect($emails)->toEqualCanonicalizing(['restaurant_admin-'.SA.'@example.com', 'waiter-'.SA.'@example.com']);
});

it('is reserved to users.manage', function () {
    [, $manager] = staffMember('manager');
    [$waiter, $waiterAuth] = staffMember('waiter');

    foreach ([$manager, $waiterAuth] as $auth) {
        $this->getJson('/api/v1/staff', $auth)->assertForbidden();
        $this->postJson('/api/v1/staff', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'waiter'], $auth)->assertForbidden();
        $this->patchJson('/api/v1/staff/'.$waiter->public_id, ['role' => 'bar'], $auth)->assertForbidden();
    }
});

it('invites a new person with a reset link and attaches an existing one', function () {
    Notification::fake();
    [, $admin] = staffMember('restaurant_admin');
    $existing = User::factory()->create(['email' => 'known@example.com']);

    $this->postJson('/api/v1/staff', ['name' => 'Ana', 'email' => 'Ana@Example.com', 'role' => 'waiter'], $admin)->assertCreated();
    $this->postJson('/api/v1/staff', ['name' => 'Ignored', 'email' => 'known@example.com', 'role' => 'kitchen'], $admin)->assertCreated();

    $ana = User::where('email', 'ana@example.com')->firstOrFail();
    Notification::assertSentTo($ana, ResetPassword::class);
    Notification::assertNotSentTo($existing, ResetPassword::class);
    expect(DB::table('restaurant_user')->where('restaurant_id', SA)->whereIn('user_id', [$ana->id, $existing->id])->count())->toBe(2)
        ->and($existing->fresh()->name)->not->toBe('Ignored');
    $this->postJson('/api/v1/staff', ['name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'bar'], $admin)->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_MEMBER');
});

it('answers an invitation for a disabled account like any other and adds nothing', function () {
    [, $admin] = staffMember('restaurant_admin');
    $off = User::factory()->create(['email' => 'off@example.com', 'is_active' => false]);

    $this->postJson('/api/v1/staff', ['name' => 'Off', 'email' => 'off@example.com', 'role' => 'waiter'], $admin)->assertCreated();

    expect(DB::table('restaurant_user')->where('user_id', $off->id)->count())->toBe(0);
});

it('changes a role or deactivates, and cuts the person\'s open access tokens', function () {
    [, $admin] = staffMember('restaurant_admin');
    [$waiter, $waiterAuth] = staffMember('waiter');
    $this->getJson('/api/v1/auth/me', $waiterAuth)->assertOk();
    sleep(1); // the cutoff has whole-second resolution: the token must predate it

    $this->patchJson('/api/v1/staff/'.$waiter->public_id, ['role' => 'kitchen'], $admin)->assertOk()->assertJsonPath('data.role', 'kitchen');
    $this->getJson('/api/v1/auth/me', $waiterAuth)->assertUnauthorized();

    $this->patchJson('/api/v1/staff/'.$waiter->public_id, ['is_active' => false], $admin)->assertOk()->assertJsonPath('data.is_active', false);
    $this->postJson('/api/v1/auth/login', ['email' => $waiter->email, 'password' => 'correct horse battery'])->assertUnauthorized();
});

it('does not touch people of another restaurant or yourself, and validates', function () {
    [$self, $admin] = staffMember('restaurant_admin');
    [$other] = staffMember('waiter', SB);

    $this->patchJson('/api/v1/staff/'.$other->public_id, ['is_active' => false], $admin)->assertNotFound();
    expect(DB::table('restaurant_user')->where('user_id', $other->id)->value('is_active'))->toBeTruthy();
    $this->patchJson('/api/v1/staff/'.$self->public_id, ['role' => 'waiter'], $admin)->assertStatus(422)->assertJsonPath('error.code', 'CANNOT_MODIFY_SELF');
    $this->postJson('/api/v1/staff', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'platform_admin'], $admin)->assertStatus(422);
    $this->postJson('/api/v1/staff', ['name' => 'X', 'email' => 'not-an-email', 'role' => 'waiter'], $admin)->assertStatus(422);
});

it('does not end the person\'s sessions in another restaurant', function () {
    [, $admin] = staffMember('restaurant_admin');
    [$waiter] = staffMember('waiter');
    member($waiter, SB, 'waiter');
    $elsewhere = app(TokenIssuer::class)->issue(new AccessClaims($waiter->public_id, SB, 'waiter', ['orders.view']));
    sleep(1);

    $this->patchJson('/api/v1/staff/'.$waiter->public_id, ['role' => 'bar'], $admin)->assertOk();

    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer $elsewhere"])->assertOk();
});
