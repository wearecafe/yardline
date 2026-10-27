/**
 * The email colour system, worked out in the browser so the branding
 * screen updates as you type. Mirrors App\Email\ColourSystem: keep the two
 * in step (the server's result is what's saved and sent).
 */

export type Tokens = Record<string, string>;

const LIGHTNESS = [
    0.975, 0.94, 0.88, 0.8, 0.71, 0.62, 0.53, 0.45, 0.38, 0.31, 0.24,
];
const CHROMA = [0.18, 0.35, 0.6, 0.85, 1, 1, 1, 0.95, 0.85, 0.72, 0.6];

export const DEFAULT_PRIMARY = '#2B2B28';

export function normalize(hex: string | null | undefined): string | null {
    const value = (hex ?? '').trim().toUpperCase();
    const match = /^#?([0-9A-F]{6})$/.exec(value);

    return match ? `#${match[1]}` : null;
}

function rgb(hex: string): [number, number, number] {
    const n = parseInt(hex.replace('#', ''), 16);

    return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255];
}

const toLinear = (c: number) =>
    c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
const fromLinear = (c: number) =>
    c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(c, 1 / 2.4) - 0.055;

function toOklch(hex: string): [number, number, number] {
    const [r, g, b] = rgb(hex).map(toLinear);
    const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
    const lightness = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
    const a = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
    const bb = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;

    return [lightness, Math.hypot(a, bb), Math.atan2(bb, a)];
}

function linearFromOklch(
    lightness: number,
    chroma: number,
    hue: number,
): [number, number, number] {
    const a = chroma * Math.cos(hue);
    const b = chroma * Math.sin(hue);
    const l = Math.pow(lightness + 0.3963377774 * a + 0.2158037573 * b, 3);
    const m = Math.pow(lightness - 0.1055613458 * a - 0.0638541728 * b, 3);
    const s = Math.pow(lightness - 0.0894841775 * a - 1.291485548 * b, 3);

    return [
        4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ];
}

function fromOklch(lightness: number, chroma: number, hue: number): string {
    let c = chroma;
    let linear = linearFromOklch(lightness, c, hue);

    for (
        let i = 0;
        i < 40 && linear.some((v) => v < -0.0005 || v > 1.0005);
        i++
    ) {
        c *= 0.9;
        linear = linearFromOklch(lightness, c, hue);
    }

    return (
        '#' +
        linear
            .map((v) =>
                Math.round(
                    Math.min(
                        1,
                        Math.max(0, fromLinear(Math.min(1, Math.max(0, v)))),
                    ) * 255,
                )
                    .toString(16)
                    .padStart(2, '0'),
            )
            .join('')
            .toUpperCase()
    );
}

function luminance(hex: string): number {
    const [r, g, b] = rgb(hex).map(toLinear);

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrast(a: string, b: string): number {
    const x = luminance(a);
    const y = luminance(b);

    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

export function generate(primaryInput: string): Tokens {
    const primary = normalize(primaryInput) ?? DEFAULT_PRIMARY;
    const [lightness, chroma, hue] = toOklch(primary);
    const ramp = LIGHTNESS.map((l, i) => fromOklch(l, chroma * CHROMA[i], hue));

    let index = 0;
    LIGHTNESS.forEach((l, i) => {
        if (Math.abs(l - lightness) < Math.abs(LIGHTNESS[index] - lightness)) {
            index = i;
        }
    });
    ramp[index] = primary;

    const strong =
        ramp.find((step) => contrast(step, '#FFFFFF') >= 4.5) ?? ramp[10];
    const ink = fromOklch(0.24, Math.min(chroma, 0.012), hue);

    return {
        primary,
        primary_hover: ramp[Math.min(index + 1, 10)],
        primary_strong: strong,
        primary_subtle: ramp[0],
        primary_border: ramp[2],
        on_primary:
            contrast(primary, '#FFFFFF') >= contrast(primary, ink)
                ? '#FFFFFF'
                : ink,
        ink,
        muted: fromOklch(0.5, Math.min(chroma, 0.015), hue),
        line: fromOklch(0.9, Math.min(chroma, 0.01), hue),
        background: fromOklch(0.965, Math.min(chroma, 0.006), hue),
        surface: '#FFFFFF',
        danger: '#B42318',
    };
}

export function resolve(
    primary: string,
    overrides: Record<string, string>,
): Tokens {
    const tokens = generate(primary);

    for (const [key, value] of Object.entries(overrides)) {
        const hex = normalize(value);

        if (key in tokens && hex) {
            tokens[key] = hex;
        }
    }

    return tokens;
}

export function button(tokens: Tokens): { fill: string; text: string } {
    if (contrast(tokens.on_primary, tokens.primary) >= 4.5) {
        return { fill: tokens.primary, text: tokens.on_primary };
    }

    return { fill: tokens.primary_strong, text: '#FFFFFF' };
}

export type Check = {
    key: string;
    label: string;
    ratio: number;
    required: boolean;
    passes: boolean;
    fg: string;
    bg: string;
};

export function checks(tokens: Tokens): Check[] {
    const b = button(tokens);
    const pairs: [string, string, string, string, boolean][] = [
        ['body', 'Body text on the card', tokens.ink, tokens.surface, true],
        ['button', 'Button text on buttons', b.text, b.fill, true],
        [
            'links',
            'Links on the card',
            tokens.primary_strong,
            tokens.surface,
            true,
        ],
        [
            'facts',
            'Text in the key facts box',
            tokens.ink,
            tokens.primary_subtle,
            true,
        ],
        [
            'muted',
            'Muted text on the card',
            tokens.muted,
            tokens.surface,
            false,
        ],
    ];

    return pairs.map(([key, label, fg, bg, required]) => {
        const ratio = Math.round(contrast(fg, bg) * 100) / 100;

        return { key, label, ratio, required, passes: ratio >= 4.5, fg, bg };
    });
}
