<?php

declare(strict_types=1);

it('shows the menu without withdrawn products, marking what cannot be ordered now', function () {
    $token = diner();
    seedProduct('P2', ['is_sold_out' => true, 'sort_order' => 1]);
    seedProduct('P3', ['is_available' => false]);

    $menu = test()->getJson('/api/v1/session/menu', bearer($token))->assertOk()->json('data');

    expect($menu['currency'])->toBe('EUR')
        ->and(array_column($menu['categories'][0]['products'], 'orderable', 'id'))->toBe(['P1' => true, 'P2' => false]);
});

it('prices the order itself, whatever the diner claims', function () {
    $token = diner();

    $order = order($token, [['product_id' => 'P1', 'variant_id' => 'V2', 'quantity' => 2, 'unit_price' => 1, 'addons' => [['addon_id' => 'A1', 'quantity' => 2]]]])
        ->assertCreated()->json('data');

    // (1000 + 300 + 2 × 50) × 2 = 2800, 10 % tax contained.
    expect($order)->toMatchArray(['status' => 'pending', 'total' => 2800, 'tax_total' => 255, 'subtotal' => 2545, 'currency' => 'EUR'])
        ->and($order['items'][0])->toMatchArray(['name' => 'Producto P1', 'variant_name' => 'Grande', 'unit_price' => 1300, 'line_total' => 2800]);
});

it('adds tax on top when the restaurant prices without it, and accepts on its own if told to', function () {
    $token = diner(['prices_include_tax' => false, 'default_tax_bps' => 2100, 'auto_accept_orders' => true]);

    order($token)->assertCreated()->assertJsonPath('data.subtotal', 1000)->assertJsonPath('data.tax_total', 210)
        ->assertJsonPath('data.total', 1210)->assertJsonPath('data.status', 'accepted');
});

it('refuses what the catalog does not offer, and orders nothing', function () {
    $token = diner();
    $refused = fn (array $item, string $code) => order($token, [['product_id' => 'P1', 'quantity' => 1], $item])
        ->assertStatus(422)->assertJsonPath('error.code', $code);

    $refused(['product_id' => 'NOPE', 'quantity' => 1], 'ORDER_PRODUCT_UNAVAILABLE');
    $refused(['product_id' => 'P1', 'variant_id' => 'NOPE', 'quantity' => 1], 'ORDER_PRODUCT_UNAVAILABLE');
    $refused(['product_id' => 'P1', 'quantity' => 1, 'addons' => [['addon_id' => 'A1', 'quantity' => 3]]], 'VALIDATION_FAILED');
    $refused(['product_id' => 'P1', 'quantity' => 1, 'addons' => [['addon_id' => 'A1', 'quantity' => 1], ['addon_id' => 'A1', 'quantity' => 1]]], 'VALIDATION_FAILED');
    $refused(['product_id' => 'P1', 'quantity' => 0], 'VALIDATION_FAILED');

    project('category', ['id' => 'C1', 'name' => 'Platos', 'sort_order' => 0, 'is_active' => false], 2);
    order($token)->assertStatus(422)->assertJsonPath('error.code', 'ORDER_PRODUCT_UNAVAILABLE');
    test()->getJson('/api/v1/session/orders', bearer($token))->assertJsonCount(0, 'data');
});

it('orders once however often the same request is sent', function () {
    $token = diner();

    order($token, key: '')->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    $first = order($token, key: 'same-key-1')->assertCreated()->json('data');
    seedProduct('P1', ['is_sold_out' => true], 2);

    expect(order($token, key: 'same-key-1')->assertCreated()->json('data.id'))->toBe($first['id']);
    order($token, [['product_id' => 'P1', 'quantity' => 2]], 'same-key-1')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    test()->getJson('/api/v1/session/orders', bearer($token))->assertJsonCount(1, 'data');
});

it('takes no orders before the waiter approves the table, and lets the key be retried afterwards', function () {
    $token = diner(['require_waiter_approval_for_session' => true]);

    order($token, key: 'waiting-1')->assertStatus(409)->assertJsonPath('error.code', 'SESSION_PENDING_APPROVAL');
    $id = test()->getJson('/api/v1/sessions', staff())->json('data.0.id');
    test()->postJson("/api/v1/sessions/$id/approve", [], staff())->assertOk();
    order($token, key: 'waiting-1')->assertCreated();
});

it('lets the staff of that restaurant decide once, keeping the history', function () {
    $token = diner();
    $id = order($token)->json('data.id');
    $waiter = staff();

    test()->postJson("/api/v1/orders/$id/accept", [], bearer($token))->assertUnauthorized();
    test()->postJson("/api/v1/orders/$id/accept", [], staff(SHOP_A, ['orders.view']))->assertForbidden();
    test()->postJson("/api/v1/orders/$id/accept", [], staff(SHOP_B))->assertNotFound();
    test()->getJson("/api/v1/orders/$id", staff(SHOP_B))->assertNotFound();
    test()->getJson('/api/v1/orders', staff(SHOP_B))->assertJsonCount(0, 'data');

    test()->postJson("/api/v1/orders/$id/accept", [], $waiter)->assertOk()->assertJsonPath('data.status', 'accepted');
    test()->postJson("/api/v1/orders/$id/accept", [], $waiter)->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    test()->postJson("/api/v1/orders/$id/reject", [], $waiter)->assertStatus(409);
    test()->postJson("/api/v1/orders/$id/cancel", ['reason' => 'Se marchan'], $waiter)->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.reason', 'Se marchan')
        ->assertJsonPath('data.items.0.status', 'cancelled')->assertJsonPath('data.version', 3);
    test()->getJson('/api/v1/orders?filter[status]=cancelled', $waiter)->assertJsonPath('meta.total', 1);
    test()->getJson('/api/v1/orders?filter[status]=pending', $waiter)->assertJsonPath('meta.total', 0);
});
