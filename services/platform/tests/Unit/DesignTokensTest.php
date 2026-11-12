<?php

declare(strict_types=1);

function token(string $name): string
{
    preg_match_all('/--([a-z-]+):\s*(#[0-9a-f]{6});/i', file_get_contents(__DIR__.'/../../resources/css/tokens.css'), $m);
    $tokens = array_combine($m[1], $m[2]);

    return $tokens[$name] ?? throw new RuntimeException("Missing design token --{$name}");
}

function luminance(string $hex): float
{
    $c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, sscanf($hex, '#%02x%02x%02x'));

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function contrast(string $a, string $b): float
{
    [$hi, $lo] = [max(luminance($a), luminance($b)), min(luminance($a), luminance($b))];

    return ($hi + 0.05) / ($lo + 0.05);
}

// WCAG 2.2 AA: 4.5:1 for text.
it('keeps text pairs at WCAG AA contrast', function (string $fg, string $bg) {
    expect(contrast(token($fg), token($bg)))->toBeGreaterThanOrEqual(4.5);
})->with([
    'body on carbon' => ['white', 'carbon'],
    'light on surface' => ['titanium-light', 'carbon-surface'],
    'muted on carbon' => ['titanium-muted', 'carbon'],
    'secondary text on surface' => ['titanium', 'carbon-surface'],
    'accent on carbon' => ['modena', 'carbon'],
    'button label on accent' => ['carbon', 'modena'],
    'button label on hover' => ['carbon', 'modena-hover'],
    'success on carbon' => ['success', 'carbon'],
    'warning on carbon' => ['warning', 'carbon'],
    'danger on carbon' => ['danger', 'carbon'],
    'info on carbon' => ['info', 'carbon'],
]);
