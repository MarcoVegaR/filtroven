import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import type { SharedUiProps } from '@/types';

export default function AppLogo() {
    const { ui } = usePage().props as { ui: SharedUiProps };

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon className="size-5 fill-current text-white" />
            </div>
            <div className="ml-1 grid flex-1 text-left leading-tight">
                <span className="truncate text-sm font-semibold">
                    {ui.branding.application}
                </span>
                <span className="truncate text-xs text-muted-foreground">
                    {ui.branding.company}
                </span>
            </div>
        </>
    );
}
