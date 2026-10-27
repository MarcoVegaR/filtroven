import type { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon({
    alt = '',
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            src="/branding/filtroven-f-proposal.png"
            alt={alt}
            width={64}
            height={64}
            {...props}
        />
    );
}
