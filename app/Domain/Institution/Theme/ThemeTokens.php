<?php

namespace App\Domain\Institution\Theme;

/**
 * Derives the primary shadcn tokens for light and dark mode from one
 * institution colour, keeping WCAG contrast:
 *  - the colour must reach 3:1 against the light background (UI components);
 *  - the dark variant is lightened until it reaches 3:1 on the dark background;
 *  - foregrounds are near-white or near-black, whichever contrasts more; for
 *    mid tones where neither reaches 4.5:1, pure white or black (one of them
 *    always does).
 */
final class ThemeTokens
{
    public const MIN_UI_CONTRAST = 3.0;

    /** Matches --background in resources/css/app.css. */
    private const LIGHT_BACKGROUND = '#ffffff';

    private const DARK_BACKGROUND = '#0a0a0a';

    private const LIGHT_TEXT = '#fafafa';

    private const DARK_TEXT = '#171717';

    /**
     * Whether a colour can be used as the institution primary colour.
     */
    public static function isUsable(string $hex): bool
    {
        return Color::fromHex($hex)->contrastWith(Color::fromHex(self::LIGHT_BACKGROUND)) >= self::MIN_UI_CONTRAST;
    }

    /**
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public static function fromPrimary(string $hex): array
    {
        $light = Color::fromHex($hex);

        return [
            'light' => self::tokens($light),
            'dark' => self::tokens(self::darkVariant($light)),
        ];
    }

    /**
     * CSS rules overriding the default tokens, or null for the neutral theme.
     */
    public static function css(?string $hex): ?string
    {
        if ($hex === null || ! self::isUsable($hex)) {
            return null;
        }

        $tokens = self::fromPrimary($hex);

        return ':root{'.self::declarations($tokens['light']).'}.dark{'.self::declarations($tokens['dark']).'}';
    }

    private static function darkVariant(Color $color): Color
    {
        $background = Color::fromHex(self::DARK_BACKGROUND);
        [$lightness, $chroma, $hue] = $color->toOklch();

        $candidate = $color;

        while ($candidate->contrastWith($background) < self::MIN_UI_CONTRAST && $lightness < 0.95) {
            $lightness += 0.02;
            $candidate = Color::fromOklch($lightness, $chroma, $hue);
        }

        return $candidate;
    }

    /**
     * @return array<string, string>
     */
    private static function tokens(Color $primary): array
    {
        $foreground = self::bestOf($primary, self::LIGHT_TEXT, self::DARK_TEXT);

        if ($primary->contrastWith($foreground) < 4.5) {
            $foreground = self::bestOf($primary, '#ffffff', '#000000');
        }

        return [
            '--primary' => $primary->toOklchCss(),
            '--primary-foreground' => $foreground->toOklchCss(),
            '--ring' => $primary->toOklchCss(),
            '--sidebar-primary' => $primary->toOklchCss(),
            '--sidebar-primary-foreground' => $foreground->toOklchCss(),
        ];
    }

    private static function bestOf(Color $background, string $first, string $second): Color
    {
        $a = Color::fromHex($first);
        $b = Color::fromHex($second);

        return $background->contrastWith($a) >= $background->contrastWith($b) ? $a : $b;
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private static function declarations(array $tokens): string
    {
        return implode('', array_map(
            static fn (string $name, string $value): string => "{$name}:{$value};",
            array_keys($tokens),
            $tokens,
        ));
    }
}
