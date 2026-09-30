<?php

use App\Domain\Institution\Theme\Color;

test('hex colours are parsed with or without a hash', function () {
    expect(Color::fromHex('#1E40AF')->toHex())->toBe('#1e40af')
        ->and(Color::fromHex('1e40af')->toHex())->toBe('#1e40af');
});

test('invalid hex colours are rejected', function (string $hex) {
    Color::fromHex($hex);
})->throws(InvalidArgumentException::class)->with(['#fff', 'red', '#12345g', '']);

test('contrast ratios follow WCAG', function () {
    $white = Color::fromHex('#ffffff');

    expect(round(Color::fromHex('#000000')->contrastWith($white), 2))->toBe(21.0)
        ->and(round(Color::fromHex('#777777')->contrastWith($white), 2))->toBe(4.48)
        ->and(round($white->contrastWith($white), 2))->toBe(1.0);
});

test('OKLCH conversion matches reference values', function () {
    [$l, $c, $h] = Color::fromHex('#1e40af')->toOklch();

    expect(round($l, 3))->toBe(0.424)
        ->and(round($c, 3))->toBe(0.181)
        ->and(round($h, 1))->toBe(265.6);

    [$l, $c] = Color::fromHex('#ffffff')->toOklch();
    expect(round($l, 3))->toBe(1.0)->and(round($c, 3))->toBe(0.0);
});

test('OKLCH round-trips to the same sRGB colour', function (string $hex) {
    $color = Color::fromHex($hex);

    expect(Color::fromOklch(...$color->toOklch())->toHex())->toBe($hex);
})->with(['#1e40af', '#ff0000', '#003366', '#777777', '#10b981']);

test('out-of-gamut colours are brought into gamut', function () {
    $hex = Color::fromOklch(0.7, 0.4, 150)->toHex();

    expect($hex)->toMatch('/^#[0-9a-f]{6}$/');
});
