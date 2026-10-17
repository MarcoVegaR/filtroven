import type { ComponentPropsWithoutRef } from 'react';
import { SidebarGroup, SidebarGroupContent } from '@/components/ui/sidebar';

export function NavFooter({
    title,
    subtitle,
    className,
    ...props
}: ComponentPropsWithoutRef<typeof SidebarGroup> & {
    title: string;
    subtitle: string;
}) {
    return (
        <SidebarGroup
            {...props}
            className={`group-data-[collapsible=icon]:p-0 ${className || ''}`}
        >
            <SidebarGroupContent>
                <div className="rounded-xl border border-sidebar-border/70 bg-sidebar-accent/40 p-4 text-sm group-data-[collapsible=icon]:hidden">
                    <p className="font-semibold text-sidebar-foreground">
                        {title}
                    </p>
                    <p className="mt-1 text-sidebar-foreground/75">
                        {subtitle}
                    </p>
                </div>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
