<?php

use App\Domain\Institution\Theme\Color;
use App\Domain\Institution\Theme\ThemeTokens;

function tokenColor(string $oklch): Color
{
    preg_match('/oklch\(([\d.]+) ([\d.]+) ([\d.]+)\)/', $oklch, $m);

    return Color::fromOklch((float) $m[1], (float) $m[2], (float) $m[3]);
}

test('colours need 3:1 contrast against white', function () {
    expect(ThemeTokens::isUsable('#1e40af'))->toBeTrue()
        ->and(ThemeTokens::isUsable('#ffff00'))->toBeFalse()
        ->and(ThemeTokens::isUsable('#eeeeee'))->toBeFalse();
});

test('no colour means the neutral theme', function () {
    expect(ThemeTokens::css(null))->toBeNull()
        ->and(ThemeTokens::css('#ffff00'))->toBeNull();
});

test('generated tokens keep WCAG contrast in both modes', function (string $hex) {
    $tokens = ThemeTokens::fromPrimary($hex);

    foreach (['light' => '#ffffff', 'dark' => '#0a0a0a'] as $mode => $background) {
        $primary = tokenColor($tokens[$mode]['--primary']);
        $foreground = tokenColor($tokens[$mode]['--primary-foreground']);

        expect($primary->contrastWith(Color::fromHex($background)))->toBeGreaterThanOrEqual(2.95, "{$hex} {$mode} vs background")
            ->and($foreground->contrastWith($primary))->toBeGreaterThanOrEqual(4.5, "{$hex} {$mode} text");
    }
})->with(['#1e40af', '#003366', '#b91c1c', '#047857', '#6d28d9', '#000000', '#767676']);

test('css overrides root and dark tokens', function () {
    $css = ThemeTokens::css('#1e40af');

    expect($css)->toStartWith(':root{--primary:oklch(')
        ->and($css)->toContain('.dark{--primary:oklch(')
        ->and($css)->not->toContain('<');
});
