import type { Auth } from '@/types/auth';
import type { Flash, SharedUiProps } from '@/types/ui';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            flash: Flash;
            sidebarOpen: boolean;
            ui: SharedUiProps;
            [key: string]: unknown;
        };
    }
}
