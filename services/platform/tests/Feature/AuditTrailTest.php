<?php

use App\Application\Audit\AuditTrail;
use App\Application\Auth\AccessClaims;
use App\Application\Auth\TokenIssuer;
use App\Infrastructure\Auth\JwtKeys;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['jwt.private_key' => JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    app()->forgetInstance(JwtKeys::class);
});

function auditActions(): array
{
    return DB::table('audit_logs')->orderBy('id')->pluck('action')->all();
}

it('is append-only at the database level', function () {
    app(AuditTrail::class)->record('test.event');

    // Savepoints: Postgres aborts the whole test transaction after the first error.
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->update(['action' => 'tampered'])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->delete()))->toThrow(QueryException::class, 'append-only');
});

it('drops secrets from metadata, even nested ones', function () {
    app(AuditTrail::class)->record('test.event', metadata: ['email' => 'a@b.c', 'password' => 'x', 'ctx' => ['refresh_token' => 'y', 'ok' => 1]]);

    expect(json_decode(DB::table('audit_logs')->value('metadata'), true))->toEqual(['email' => 'a@b.c', 'ctx' => ['ok' => 1]]);
});

it('audits the login lifecycle without storing credentials', function () {
    $admin = User::factory()->create(['email' => 'admin@example.com', 'password' => 'correct horse battery']);
    $admin->forceFill(['is_platform_admin' => true])->save();

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong password!!'])->assertUnauthorized();
    $data = $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'correct horse battery'])->json('data');
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $data['refresh_token']])->assertOk();
    $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$data['access_token']}"])->assertNoContent();

    expect(auditActions())->toBe(['auth.login_failed', 'auth.login', 'auth.refresh', 'auth.logout']);
    $dump = json_encode(DB::table('audit_logs')->get());
    expect($dump)->not->toContain('correct horse')->not->toContain('wrong password')->not->toContain($data['refresh_token'])
        ->and(DB::table('audit_logs')->where('action', 'auth.login')->value('actor_user_id'))->toBe($admin->public_id);
});

it('audits administrative actions with the acting admin, and denied access', function () {
    $keys = app(JwtKeys::class);
    $actor = platformAdmin()->public_id;
    $issue = fn (string $role) => ['Authorization' => 'Bearer '.app(TokenIssuer::class)->issue(new AccessClaims($actor, null, $role, []))];

    $id = $this->postJson('/api/v1/admin/restaurants', ['name' => 'La Brasa', 'slug' => 'labrasa'], $issue('platform_admin'))->json('data.id');
    $this->postJson("/api/v1/admin/restaurants/$id/suspend", [], $issue('platform_admin'))->assertStatus(409); // not active: not audited
    $this->postJson('/api/v1/admin/restaurants', [], $issue('waiter'))->assertForbidden();
    $this->postJson('/api/v1/admin/restaurants', [], ['Authorization' => 'Bearer garbage'])->assertUnauthorized();

    expect(auditActions())->toBe(['restaurant.registered', 'access.denied', 'access.denied']);
    $registered = DB::table('audit_logs')->where('action', 'restaurant.registered')->first();
    expect($registered->actor_user_id)->toBe($actor)->and($registered->subject_id)->toBe($id);
});
