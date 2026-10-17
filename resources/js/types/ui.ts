import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

/** Flash data shared by HandleInertiaRequests */
export type Flash = {
    success: string | null;
    error: string | null;
    info: string | null;
    warning: string | null;
};

/** Laravel paginator link shape as serialized by Inertia */
export type PaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
};

/** Generic paginated response from Laravel's ->paginate() */
export type PaginatedData<T> = {
    data: T[];
    links: PaginatorLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};

export type SharedUiLink = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: string | null;
    dataTest?: string | null;
};

export type SharedUiProps = {
    locale: string;
    branding: {
        application: string;
        company: string;
        footerSubtitle: string;
        shellTagline: string;
        mobileBlurb: string;
    };
    navigation: {
        label: string;
        items: SharedUiLink[];
        starterPromoLinksRemoved: boolean;
    };
    settingsSection: {
        title: string;
        description: string;
        ariaLabel: string;
    };
    settingsNavigation: SharedUiLink[];
    appearance: {
        palette: string;
        defaultMode: 'light' | 'dark' | 'system';
        supportedModes: Array<'light' | 'dark' | 'system'>;
        labels: Record<'light' | 'dark' | 'system', string>;
    };
};
