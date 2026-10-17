import { Link, usePage } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import type { PropsWithChildren } from 'react';

import { PageHeader } from '@/components/system/page-header';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import type { SharedUiProps } from '@/types';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { ui } = usePage().props as { ui: SharedUiProps };

    if (typeof window === 'undefined') {
        return null;
    }

    return (
        <div className="space-y-6 px-4 py-6 sm:px-6">
            <PageHeader
                icon={Settings}
                title={ui.settingsSection.title}
                description={ui.settingsSection.description}
            />

            <div className="flex flex-col gap-8 lg:flex-row">
                <aside className="w-full shrink-0 lg:w-52">
                    <nav
                        className="flex flex-col gap-1"
                        aria-label={ui.settingsSection.ariaLabel}
                    >
                        {ui.settingsNavigation.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn(
                                    'w-full justify-start transition-colors',
                                    isCurrentOrParentUrl(item.href)
                                        ? 'bg-primary/10 font-medium text-primary hover:bg-primary/15'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Link href={item.href}>{item.title}</Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
