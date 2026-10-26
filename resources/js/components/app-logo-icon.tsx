import type { SVGAttributes } from 'react';

/**
 * Yardline mark: three stacked courses, like a pallet of block seen end-on,
 * set on a ruled baseline.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
            <rect x="4" y="6" width="14" height="5" rx="0.5" />
            <rect x="4" y="13" width="20" height="5" rx="0.5" />
            <rect x="4" y="20" width="24" height="5" rx="0.5" />
            <rect x="4" y="27" width="24" height="1.5" />
        </svg>
    );
}
