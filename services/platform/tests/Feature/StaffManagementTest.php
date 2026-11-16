<?php

use App\Application\Auth\TokenIssuer;
use App\Infrastructure\Auth\JwtKeys;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Firebase\JWT\JWT;
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
    $email ??= strtolower("$role-$restaurant@example.com");
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

    expect($emails)->toEqualCanonicalizing([strtolower('restaurant_admin-'.SA.'@example.com'), strtolower('waiter-'.SA.'@example.com')]);
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

/** Invites through the API and returns the token that went out by mail. */
function invite(array $auth, string $email, string $role = 'waiter'): string
{
    Notification::fake();
    test()->postJson('/api/v1/staff', ['name' => 'Ana', 'email' => $email, 'role' => $role], $auth)->assertCreated();
    $token = null;
    Notification::assertSentOnDemand(StaffInvitation::class, function ($n, $channels, $route) use ($email, &$token) {
        $token = (fn () => $this->token)->call($n);

        return $route->routes['mail'] === strtolower($email);
    });

    return $token;
}

it('invites without attaching anything, then a new person accepts and sets a password', function () {
    [, $admin] = staffMember('restaurant_admin');

    $token = invite($admin, 'Ana@Example.com', 'kitchen');
    expect(User::where('email', 'ana@example.com')->exists())->toBeFalse()
        ->and(DB::table('staff_invitations')->count())->toBe(1);

    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $token, 'password' => 'short'])->assertStatus(422);
    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $token, 'password' => 'a long enough passphrase'])->assertNoContent();

    $ana = User::where('email', 'ana@example.com')->firstOrFail();
    expect(DB::table('restaurant_user')->where('restaurant_id', SA)->where('user_id', $ana->id)->exists())->toBeTrue()
        ->and(DB::table('staff_invitations')->count())->toBe(0);
    $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'a long enough passphrase'])->assertOk();
    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $token, 'password' => 'a long enough passphrase'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_INVITATION');
});

it('does not touch an existing account until its owner accepts with the account password', function () {
    [, $admin] = staffMember('restaurant_admin');
    $existing = User::factory()->create(['email' => 'known@example.com', 'name' => 'Known', 'password' => 'correct horse battery']);

    $token = invite($admin, 'known@example.com');
    expect(DB::table('restaurant_user')->where('user_id', $existing->id)->count())->toBe(0);

    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $token, 'password' => 'not the password'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    expect(DB::table('restaurant_user')->where('user_id', $existing->id)->count())->toBe(0);

    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $token, 'password' => 'correct horse battery'])->assertNoContent();
    expect(DB::table('restaurant_user')->where('restaurant_id', SA)->where('user_id', $existing->id)->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('Known');
});

it('rejects expired, unknown and disabled-account invitations alike, and an existing member', function () {
    [, $admin] = staffMember('restaurant_admin');
    $off = User::factory()->create(['email' => 'off@example.com', 'is_active' => false, 'password' => 'correct horse battery']);
    $token = invite($admin, 'off@example.com');
    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $token, 'password' => 'correct horse battery'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_INVITATION');
    expect(DB::table('restaurant_user')->where('user_id', $off->id)->count())->toBe(0);

    $late = invite($admin, 'late@example.com');
    $this->postJson('/api/v1/auth/accept-invitation', ['token' => 'nope', 'password' => 'a long enough passphrase'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_INVITATION');
    DB::table('staff_invitations')->where('email', 'late@example.com')->update(['expires_at' => now()->subSecond()]);
    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $late, 'password' => 'a long enough passphrase'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_INVITATION');

    [$waiter] = staffMember('waiter');
    $this->postJson('/api/v1/staff', ['name' => 'W', 'email' => $waiter->email, 'role' => 'bar'], $admin)->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_MEMBER');
});

it('replaces the pending invitation when inviting again, so the old link stops working', function () {
    [, $admin] = staffMember('restaurant_admin');
    $first = invite($admin, 'ana@example.com');
    $second = invite($admin, 'ana@example.com', 'bar');

    expect(DB::table('staff_invitations')->count())->toBe(1);
    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $first, 'password' => 'a long enough passphrase'])->assertStatus(422);
    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $second, 'password' => 'a long enough passphrase'])->assertNoContent();
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
