import { usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { resolveIcon } from '@/lib/system';
import { dashboard } from '@/routes';
import type { NavItem, SharedUiProps } from '@/types';

export function AppSidebar() {
    const { ui } = usePage().props as { ui: SharedUiProps };
    const mainNavItems: NavItem[] = ui.navigation.items.map((item) => ({
        ...item,
        icon: resolveIcon(item.icon) ?? LayoutGrid,
    }));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} label={ui.navigation.label} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter
                    title={ui.branding.company}
                    subtitle={ui.branding.footerSubtitle}
                    className="mt-auto"
                />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
