<?php

declare(strict_types=1);

use Random\Engine\Mt19937;
use Random\Randomizer;

function open(string $qr, array $body = []): array
{
    return test()->postJson("/api/v1/public/tables/$qr/sessions", $body)->assertCreated()->json('data');
}

function join_(string $qr, string $code)
{
    return test()->postJson('/api/v1/public/sessions/join', ['qr_token' => $qr, 'join_code' => $code]);
}

function me(string $token)
{
    return test()->getJson('/api/v1/session', ['Authorization' => "Bearer $token"]);
}

function withoutApproval(string $tenant = SHOP_A, int $cooldown = 15): void
{
    static $sequence = 0;
    project('settings', ['id' => $tenant, 'require_waiter_approval_for_session' => false, 'join_code_reuse_cooldown_minutes' => $cooldown], ++$sequence, $tenant);
}

it('tells what the scanned table is, and nothing for an unknown, rotated or disabled QR', function () {
    $qr = seedTable();

    $this->getJson("/api/v1/public/tables/$qr")->assertOk()->assertExactJson(['data' => ['table' => ['label' => 'Mesa T1'], 'has_session' => false]]);
    $this->getJson('/api/v1/public/tables/'.str_repeat('x', 43))->assertNotFound();

    project('zone', ['id' => 'Z1', 'is_active' => false], 2);
    $this->getJson("/api/v1/public/tables/$qr")->assertNotFound();
    $this->postJson("/api/v1/public/tables/$qr/sessions")->assertNotFound();
    project('zone', ['id' => 'Z1', 'is_active' => true], 3);
    project('table', ['id' => 'T1', 'zone_id' => 'Z1', 'label' => 'Mesa T1', 'is_active' => true, 'qr_token' => str_repeat('n', 43)], 2);
    $this->getJson("/api/v1/public/tables/$qr")->assertNotFound();
});

it('opens one session per table and waits for the waiter by default', function () {
    $qr = seedTable();

    $data = open($qr, ['display_name' => 'Ana']);

    expect($data['participant_token'])->toStartWith(SHOP_A.'.')
        ->and($data['session'])->toMatchArray(['status' => 'pending_approval', 'table_label' => 'Mesa T1', 'join_code' => null]);
    $this->postJson("/api/v1/public/tables/$qr/sessions")->assertStatus(409)->assertJsonPath('error.code', 'SESSION_EXISTS');
    $this->getJson("/api/v1/public/tables/$qr")->assertJsonPath('data.has_session', true);
    me($data['participant_token'])->assertOk()->assertJsonPath('data.session.status', 'pending_approval')->assertJsonPath('data.me.display_name', 'Ana');
});

it('lets the waiter approve once, after which the code is shown', function () {
    $token = open(seedTable())['participant_token'];

    $listed = $this->getJson('/api/v1/sessions', staff())->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    $this->postJson("/api/v1/sessions/{$listed['id']}/approve", [], staff(SHOP_A, ['orders.view']))->assertForbidden();
    $this->postJson("/api/v1/sessions/{$listed['id']}/approve", [], staff(SHOP_B))->assertNotFound();
    $this->getJson('/api/v1/sessions', staff(SHOP_B))->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/sessions')->assertUnauthorized();

    $this->postJson("/api/v1/sessions/{$listed['id']}/approve", [], staff())->assertOk()->assertJsonPath('data.status', 'open');
    $this->postJson("/api/v1/sessions/{$listed['id']}/approve", [], staff())->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    expect(me($token)->assertOk()->json('data.session.join_code'))->toMatch('/^[2-9A-HJKMNP-Z]{6}$/');
});

it('lets others join with the code of that table only', function () {
    withoutApproval();
    $one = seedTable('T1');
    $two = seedTable('T2');
    $code = open($one)['session']['join_code'];
    open($two);

    join_($two, $code)->assertStatus(422)->assertJsonPath('error.code', 'JOIN_CODE_INVALID');
    $second = join_($one, strtolower($code))->assertCreated()->json('data');
    join_($one, $code)->assertCreated(); // the code stays valid while the session is open

    me($second['participant_token'])->assertOk()->assertJsonPath('data.session.table_label', 'Mesa T1')->assertJsonPath('data.participants', 3);
});

it('locks the table after five wrong codes from one address, even for the right one', function () {
    withoutApproval();
    $qr = seedTable();
    $code = open($qr)['session']['join_code'];

    foreach (range(1, 4) as $i) {
        join_($qr, '222222')->assertStatus(422);
    }
    join_($qr, $code)->assertCreated(); // successes do not count
    join_($qr, '222222')->assertStatus(422);

    join_($qr, $code)->assertStatus(429)->assertJsonPath('error.code', 'JOIN_CODE_LOCKED')->assertHeader('Retry-After');
    $this->travel(11)->minutes();
    join_($qr, $code)->assertCreated();
});

it('accepts a participant only with its own credential and only while the session lives', function () {
    withoutApproval();
    $token = open(seedTable())['participant_token'];
    [, $secret] = explode('.', $token);
    $waiter = staff(); // also serves the JWKS, so a diner's token is refused as a staff one rather than unverifiable

    me(SHOP_B.".$secret")->assertUnauthorized();
    me('01JCCCCCCCCCCCCCCCCCCCCCC7.'.$secret)->assertUnauthorized();
    me(SHOP_A.'.wrong')->assertUnauthorized();
    me('garbage')->assertUnauthorized();
    $this->getJson('/api/v1/session')->assertUnauthorized();
    $this->getJson('/api/v1/sessions', ['Authorization' => "Bearer $token"])->assertUnauthorized();

    $id = $this->getJson('/api/v1/sessions', staff())->json('data.0.id');
    $this->postJson("/api/v1/sessions/$id/close", [], staff())->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("/api/v1/sessions/$id/close", [], staff())->assertStatus(409);

    me($token)->assertStatus(409)->assertJsonPath('error.code', 'SESSION_CLOSED');
});

it('does not hand out a recently used code again until the cooldown passes', function () {
    app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937(7))); // every session draws the same sequence
    withoutApproval();
    $qr = seedTable();
    $close = fn () => $this->postJson('/api/v1/sessions/'.$this->getJson('/api/v1/sessions', staff())->json('data.0.id').'/close', [], staff())->assertOk();

    $first = open($qr)['session']['join_code'];
    $close();
    $second = open($qr)['session']['join_code'];
    $close();
    $this->travel(16)->minutes();
    $third = open($qr)['session']['join_code'];

    expect($second)->not->toBe($first)->and($third)->toBe($first);
});

it('lets a new diner take over a request nobody approved in 15 minutes', function () {
    $qr = seedTable();
    $stale = open($qr)['participant_token'];

    $this->travel(16)->minutes();
    open($qr);

    me($stale)->assertStatus(409)->assertJsonPath('error.code', 'SESSION_CLOSED');
});
