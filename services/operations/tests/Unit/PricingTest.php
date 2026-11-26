<?php

use App\Domain\Pricing;

it('takes hours that cross midnight or have an open end', function (?string $from, ?string $until, string $time, bool $open) {
    expect(Pricing::inHours($from, $until, $time))->toBe($open);
})->with([
    [null, null, '03:00', true], ['12:00', '16:00', '12:00', true], ['12:00', '16:00', '16:00', false],
    ['20:00', '02:00', '23:30', true], ['20:00', '02:00', '01:59', true], ['20:00', '02:00', '12:00', false],
    ['09:00', null, '08:59', false], [null, '11:00', '10:59', true],
]);

it('rounds tax half up, contained in the price or added to it', function () {
    expect(Pricing::tax(1100, 1000, true))->toBe(100)
        ->and(Pricing::tax(1000, 1000, false))->toBe(100)
        ->and(Pricing::tax(5, 1000, false))->toBe(1)
        ->and(Pricing::tax(4, 1000, false))->toBe(0)
        ->and(Pricing::tax(999, 0, true))->toBe(0);
});
