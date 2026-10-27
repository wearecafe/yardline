<?php

namespace App\Email;

/**
 * Builds a tenant's email colours from one primary colour.
 *
 * The primary is placed on an 11-step ramp built in OKLCH (perceptual
 * lightness, chroma, hue), keeping its hue. Roles are then picked by
 * contrast rather than by fixed step, so links and button text are always
 * readable whatever colour a yard chooses. Changing this class changes
 * every tenant's emails, so bump VERSION when the output changes.
 */
final class ColourSystem
{
    public const VERSION = 1;

    public const DEFAULT_PRIMARY = '#2B2B28';

    /**
     * Every token, in the order the settings screen lists them.
     *
     * @var array<string, array{0: string, 1: string}> token => [label, used for]
     */
    public const TOKENS = [
        'primary' => ['Primary', 'Header bar, buttons'],
        'primary_hover' => ['Primary hover', 'Button hover in the portal'],
        'primary_strong' => ['Primary strong', 'Links, amounts, accents in text'],
        'primary_subtle' => ['Primary subtle', 'Key facts box, selected rows'],
        'primary_border' => ['Primary border', 'Key facts border, focus rings'],
        'on_primary' => ['On primary', 'Text on buttons and Brand headers'],
        'ink' => ['Ink', 'Headings and body text'],
        'muted' => ['Muted', 'Labels, footer text'],
        'line' => ['Line', 'Dividers and table borders'],
        'background' => ['Background', 'Behind the email card'],
        'surface' => ['Surface', 'Email card'],
        'danger' => ['Danger', 'Overdue and declined banners'],
    ];

    /** Lightness of each ramp step, 50 to 950. */
    private const LIGHTNESS = [0.975, 0.94, 0.88, 0.80, 0.71, 0.62, 0.53, 0.45, 0.38, 0.31, 0.24];

    /** Chroma eases off at both ends so every step stays in sRGB. */
    private const CHROMA = [0.18, 0.35, 0.6, 0.85, 1, 1, 1, 0.95, 0.85, 0.72, 0.6];

    /**
     * @return array<string, string> token => #RRGGBB
     */
    public static function generate(string $primary): array
    {
        $primary = self::normalize($primary) ?? self::DEFAULT_PRIMARY;
        [$l, $c, $h] = self::toOklch($primary);

        $ramp = [];

        foreach (self::LIGHTNESS as $i => $lightness) {
            $ramp[$i] = self::fromOklch($lightness, $c * self::CHROMA[$i], $h);
        }

        // The colour the yard chose sits on the ramp unchanged.
        $index = 0;

        foreach (self::LIGHTNESS as $i => $lightness) {
            if (abs($lightness - $l) < abs(self::LIGHTNESS[$index] - $l)) {
                $index = $i;
            }
        }

        $ramp[$index] = $primary;

        $strong = $ramp[10];

        foreach ($ramp as $step) {
            if (self::contrast($step, '#FFFFFF') >= 4.5) {
                $strong = $step;
                break;
            }
        }

        $ink = self::fromOklch(0.24, min($c, 0.012), $h);

        return [
            'primary' => $primary,
            'primary_hover' => $ramp[min($index + 1, 10)],
            'primary_strong' => $strong,
            'primary_subtle' => $ramp[0],
            'primary_border' => $ramp[2],
            'on_primary' => self::contrast($primary, '#FFFFFF') >= self::contrast($primary, $ink) ? '#FFFFFF' : $ink,
            'ink' => $ink,
            'muted' => self::fromOklch(0.50, min($c, 0.015), $h),
            'line' => self::fromOklch(0.90, min($c, 0.01), $h),
            'background' => self::fromOklch(0.965, min($c, 0.006), $h),
            'surface' => '#FFFFFF',
            'danger' => '#B42318',
        ];
    }

    /**
     * The generated colours with the tenant's own changes laid over them.
     * Unknown tokens and invalid colours are ignored.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, string>
     */
    public static function resolve(string $primary, array $overrides = []): array
    {
        $tokens = self::generate($primary);

        foreach ($overrides as $token => $value) {
            if (isset($tokens[$token]) && is_string($value) && ($hex = self::normalize($value)) !== null) {
                $tokens[$token] = $hex;
            }
        }

        return $tokens;
    }

    /**
     * Button colours. When neither white nor ink is readable on the primary
     * (some mid-tone oranges and teals), buttons use Primary strong with
     * white text and the primary stays on the header bar.
     *
     * @param  array<string, string>  $tokens
     * @return array{fill: string, text: string}
     */
    public static function button(array $tokens): array
    {
        if (self::contrast($tokens['on_primary'], $tokens['primary']) >= 4.5) {
            return ['fill' => $tokens['primary'], 'text' => $tokens['on_primary']];
        }

        return ['fill' => $tokens['primary_strong'], 'text' => '#FFFFFF'];
    }

    /**
     * The pairs customer emails depend on. Required ones must reach 4.5:1
     * before the tenant can save.
     *
     * @param  array<string, string>  $tokens
     * @return list<array{key: string, label: string, ratio: float, required: bool, passes: bool}>
     */
    public static function checks(array $tokens): array
    {
        $button = self::button($tokens);
        $pairs = [
            ['body', 'Body text on the card', $tokens['ink'], $tokens['surface'], true],
            ['button', 'Button text on buttons', $button['text'], $button['fill'], true],
            ['links', 'Links on the card', $tokens['primary_strong'], $tokens['surface'], true],
            ['facts', 'Text in the key facts box', $tokens['ink'], $tokens['primary_subtle'], true],
            ['muted', 'Muted text on the card', $tokens['muted'], $tokens['surface'], false],
        ];

        return array_map(function (array $pair) {
            $ratio = round(self::contrast($pair[2], $pair[3]), 2);

            return ['key' => $pair[0], 'label' => $pair[1], 'ratio' => $ratio, 'required' => $pair[4], 'passes' => $ratio >= 4.5];
        }, $pairs);
    }

    public static function normalize(?string $hex): ?string
    {
        $hex = strtoupper(trim((string) $hex));

        if (! preg_match('/^#?([0-9A-F]{6})$/', $hex, $match)) {
            return null;
        }

        return '#'.$match[1];
    }

    public static function contrast(string $a, string $b): float
    {
        $x = self::luminance($a);
        $y = self::luminance($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(fn (float $v) => self::toLinear($v), self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rgb(string $hex): array
    {
        $n = (int) hexdec(ltrim($hex, '#'));

        return [(($n >> 16) & 255) / 255, (($n >> 8) & 255) / 255, ($n & 255) / 255];
    }

    private static function toLinear(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function fromLinear(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1 / 2.4)) - 0.055;
    }

    /**
     * @return array{0: float, 1: float, 2: float} lightness, chroma, hue (radians)
     */
    private static function toOklch(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (float $v) => self::toLinear($v), self::rgb($hex));

        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        return [$lightness, hypot($a, $bb), atan2($bb, $a)];
    }

    private static function fromOklch(float $lightness, float $chroma, float $hue): string
    {
        // Pull chroma in until the colour fits sRGB.
        for ($i = 0; $i < 40; $i++) {
            $linear = self::linearFromOklch($lightness, $chroma, $hue);

            if (min($linear) >= -0.0005 && max($linear) <= 1.0005) {
                break;
            }

            $chroma *= 0.9;
        }

        $linear = self::linearFromOklch($lightness, $chroma, $hue);

        return '#'.implode('', array_map(function (float $v) {
            $v = self::fromLinear(min(1, max(0, $v)));

            return str_pad(strtoupper(dechex((int) round(min(1, max(0, $v)) * 255))), 2, '0', STR_PAD_LEFT);
        }, $linear));
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function linearFromOklch(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos($hue);
        $b = $chroma * sin($hue);

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }
}
