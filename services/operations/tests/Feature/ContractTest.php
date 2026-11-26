<?php

declare(strict_types=1);

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Mestaro\Kernel\Testing\OpenApiContract;

/** Fails unless the response fits what docs/openapi.yaml documents for this operation and status. */
function documented(string $method, string $path, $response): void
{
    expect(OpenApiContract::check($method, $path, $response->getStatusCode(), (string) $response->getContent()))->toBeNull();
}

it('documents every operations route and nothing that does not exist', function () {
    $actual = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/') && ! str_contains($r->uri(), '_probe'))
        ->flatMap(fn (LaravelRoute $r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS']))
            ->map(fn ($m) => $m.' /'.str_replace('{qr}', '{qr_token}', substr($r->uri(), strlen('api/v1/')))))
        ->sort()->values()->all();

    expect(OpenApiContract::operations('operations'))->toBe($actual);
});

it('answers the documented shapes', function () {
    $qr = seedTable('01j00000000000000000000001');
    $show = '/public/tables/{qr_token}';
    $open = '/public/tables/{qr_token}/sessions';
    $join = '/public/sessions/join';

    documented('GET', $show, $this->getJson("/api/v1/public/tables/$qr"));
    documented('GET', $show, $this->getJson('/api/v1/public/tables/'.str_repeat('x', 43)));
    $opened = $this->postJson("/api/v1/public/tables/$qr/sessions", ['display_name' => 'Ana']);
    documented('POST', $open, $opened);
    documented('POST', $open, $this->postJson("/api/v1/public/tables/$qr/sessions"));
    documented('POST', $open, $this->postJson("/api/v1/public/tables/$qr/sessions", ['display_name' => str_repeat('a', 41)]));
    $diner = ['Authorization' => 'Bearer '.$opened->json('data.participant_token')];
    documented('GET', '/session', $this->getJson('/api/v1/session', $diner));

    $waiter = staff();
    $list = $this->getJson('/api/v1/sessions', $waiter);
    documented('GET', '/sessions', $list);
    documented('GET', '/sessions', $this->getJson('/api/v1/sessions', staff(SHOP_A, [])));
    documented('GET', '/session', $this->getJson('/api/v1/session', ['Authorization' => 'Bearer nope']));
    $id = $list->json('data.0.id');
    documented('POST', '/sessions/{id}/approve', $this->postJson("/api/v1/sessions/$id/approve", [], $waiter));
    documented('POST', '/sessions/{id}/approve', $this->postJson("/api/v1/sessions/$id/approve", [], $waiter));

    $code = $this->getJson('/api/v1/session', $diner)->json('data.session.join_code');
    documented('POST', $join, $this->postJson('/api/v1/public/sessions/join', ['qr_token' => $qr, 'join_code' => $code]));
    documented('POST', $join, $this->postJson('/api/v1/public/sessions/join', ['qr_token' => $qr, 'join_code' => '222222']));
    documented('POST', '/sessions/{id}/close', $this->postJson("/api/v1/sessions/$id/close", [], $waiter));
    documented('POST', '/sessions/{id}/close', $this->postJson('/api/v1/sessions/01JNOPE0000000000000000000/close', [], $waiter));
    documented('GET', '/session', $this->getJson('/api/v1/session', $diner));
});
