import type { LucideIcon } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

type PageHeaderProps = {
    icon?: LucideIcon;
    title: string;
    description?: string;
    actions?: React.ReactNode;
    className?: string;
};

function PageHeader({
    icon: Icon,
    title,
    description,
    actions,
    className,
}: PageHeaderProps) {
    return (
        <div
            data-slot="page-header"
            className={cn(
                'flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between',
                className,
            )}
        >
            <div className="flex items-start gap-4">
                {Icon && (
                    <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary ring-1 ring-primary/20">
                        <Icon className="size-5" />
                    </div>
                )}
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {title}
                    </h1>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
            </div>

            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}

export { PageHeader };
export type { PageHeaderProps };
