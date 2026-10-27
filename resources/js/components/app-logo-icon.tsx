import type { SVGAttributes } from 'react';

type Props = SVGAttributes<SVGElement> & {
    /** Colour of the lead brick. Pass "currentColor" for a one-colour mark. */
    accent?: string;
};

/** Safety orange, the only colour in the YardRunner mark. */
const LOGO_ACCENT = '#F26B1D';

/**
 * YardRunner mark: ten bricks in a running bond, stacked into a forward
 * chevron. The lead brick at the tip is safety orange; the rest take the
 * current fill colour.
 */
export default function AppLogoIcon({ accent = LOGO_ACCENT, ...props }: Props) {
    return (
        <svg
            {...props}
            viewBox="0 0 120 120"
            xmlns="http://www.w3.org/2000/svg"
        >
            <rect x="10" y="2" width="44" height="20" rx="3" />
            <rect x="58" y="2" width="28" height="20" rx="3" />
            <rect x="22" y="26" width="28" height="20" rx="3" />
            <rect x="54" y="26" width="44" height="20" rx="3" />
            <rect x="34" y="50" width="44" height="20" rx="3" />
            <rect x="82" y="50" width="28" height="20" rx="3" fill={accent} />
            <rect x="22" y="74" width="28" height="20" rx="3" />
            <rect x="54" y="74" width="44" height="20" rx="3" />
            <rect x="10" y="98" width="44" height="20" rx="3" />
            <rect x="58" y="98" width="28" height="20" rx="3" />
        </svg>
    );
}
