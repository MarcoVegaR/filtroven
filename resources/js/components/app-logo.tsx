import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import type { SharedUiProps } from '@/types';

export default function AppLogo() {
    const { ui } = usePage().props as { ui: SharedUiProps };

    return (
        <>
            <div className="flex size-10 shrink-0 items-center justify-center group-data-[collapsible=icon]:size-8">
                <img
                    src="/branding/filtroven-logo-flat-96.png"
                    alt=""
                    width={96}
                    height={87}
                    className="h-auto w-10 group-data-[collapsible=icon]:hidden"
                />
                <AppLogoIcon className="hidden size-8 group-data-[collapsible=icon]:block" />
            </div>
            <div className="ml-1 grid flex-1 text-left leading-tight group-data-[collapsible=icon]:hidden">
                <span className="truncate text-sm font-semibold">
                    {ui.branding.application}
                </span>
                <span className="truncate text-xs opacity-80">
                    {ui.branding.footerSubtitle}
                </span>
            </div>
        </>
    );
}
