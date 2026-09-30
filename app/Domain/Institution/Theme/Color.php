<?php

namespace App\Domain\Institution\Theme;

use InvalidArgumentException;

/**
 * An sRGB colour with the conversions needed for theming: WCAG relative
 * luminance / contrast and OKLCH (the colour space of the shadcn tokens).
 *
 * OKLab maths: Björn Ottosson, "A perceptual color space for image processing".
 */
final readonly class Color
{
    /**
     * @param  float  $r  Gamma-encoded sRGB channel, 0..1.
     */
    private function __construct(public float $r, public float $g, public float $b) {}

    public static function fromHex(string $hex): self
    {
        if (preg_match('/^#?([0-9a-f]{6})$/i', $hex, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid hex colour [{$hex}].");
        }

        [$r, $g, $b] = array_map(
            static fn (string $pair): float => hexdec($pair) / 255,
            str_split($matches[1], 2),
        );

        return new self($r, $g, $b);
    }

    public static function fromOklch(float $lightness, float $chroma, float $hue): self
    {
        // Reduce chroma until the colour fits into the sRGB gamut.
        for ($c = $chroma; $c > 0.0; $c -= 0.005) {
            $linear = self::oklchToLinear($lightness, $c, $hue);

            if (min($linear) >= -0.0001 && max($linear) <= 1.0001) {
                break;
            }
        }

        $linear ??= self::oklchToLinear($lightness, 0.0, $hue);

        [$r, $g, $b] = array_map(
            static fn (float $channel): float => self::encode(max(0.0, min(1.0, $channel))),
            $linear,
        );

        return new self($r, $g, $b);
    }

    public function toHex(): string
    {
        return sprintf(
            '#%02x%02x%02x',
            (int) round($this->r * 255),
            (int) round($this->g * 255),
            (int) round($this->b * 255),
        );
    }

    /**
     * WCAG 2.x relative luminance.
     */
    public function luminance(): float
    {
        return 0.2126 * self::decode($this->r)
            + 0.7152 * self::decode($this->g)
            + 0.0722 * self::decode($this->b);
    }

    /**
     * WCAG 2.x contrast ratio (1..21).
     */
    public function contrastWith(self $other): float
    {
        $lighter = max($this->luminance(), $other->luminance());
        $darker = min($this->luminance(), $other->luminance());

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @return array{0: float, 1: float, 2: float} lightness 0..1, chroma, hue in degrees
     */
    public function toOklch(): array
    {
        $r = self::decode($this->r);
        $g = self::decode($this->g);
        $b = self::decode($this->b);

        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $chroma = sqrt($a ** 2 + $bb ** 2);
        $hue = $chroma < 0.0001 ? 0.0 : fmod(rad2deg(atan2($bb, $a)) + 360, 360);

        return [$lightness, $chroma, $hue];
    }

    public function toOklchCss(): string
    {
        [$lightness, $chroma, $hue] = $this->toOklch();

        return sprintf('oklch(%.4f %.4f %.2f)', $lightness, $chroma, $hue);
    }

    /**
     * @return array{0: float, 1: float, 2: float} linear sRGB
     */
    private static function oklchToLinear(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    private static function decode(float $channel): float
    {
        return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    private static function encode(float $channel): float
    {
        return $channel <= 0.0031308 ? $channel * 12.92 : 1.055 * $channel ** (1 / 2.4) - 0.055;
    }
}
