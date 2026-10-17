import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
            <path d="M24 4C12.954 4 4 12.954 4 24s8.954 20 20 20c7.122 0 13.374-3.722 16.919-9.328l-7.391-4.27C31.456 33.648 27.994 36 24 36c-6.627 0-12-5.373-12-12s5.373-12 12-12c3.994 0 7.456 2.352 9.528 5.598l7.391-4.27C37.374 7.722 31.122 4 24 4Z" />
            <path d="M28 14.5 38 20v8L28 33.5l-3.5-2.021L31 27.706V20.294l-6.5-3.773L28 14.5Z" />
        </svg>
    );
}
