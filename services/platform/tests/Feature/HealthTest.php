<?php

it('reports healthy and uses the dedicated test database', function () {
    $this->get('/up')->assertOk();

    expect(config('database.default'))->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('mestaro_platform_test');
});
