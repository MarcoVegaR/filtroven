import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

type StatCardProps = {
    icon?: LucideIcon;
    label: string;
    value: number | string;
    accent?: 'default' | 'primary' | 'success' | 'warning';
    className?: string;
};

const accentStyles = {
    default: 'border-border bg-card',
    primary:
        'border-primary/20 bg-primary/5 dark:border-primary/30 dark:bg-primary/10',
    success:
        'border-emerald-200 bg-emerald-50/50 dark:border-emerald-800/40 dark:bg-emerald-950/20',
    warning:
        'border-amber-200 bg-amber-50/50 dark:border-amber-800/40 dark:bg-amber-950/20',
};

const iconAccentStyles = {
    default: 'text-muted-foreground',
    primary: 'text-primary',
    success: 'text-emerald-600 dark:text-emerald-400',
    warning: 'text-amber-600 dark:text-amber-400',
};

function StatCard({
    icon: Icon,
    label,
    value,
    accent = 'default',
    className,
}: StatCardProps) {
    return (
        <div
            data-slot="stat-card"
            className={cn(
                'rounded-xl border p-5 transition-shadow hover:shadow-md',
                accentStyles[accent],
                className,
            )}
        >
            <div className="flex items-center gap-2">
                {Icon && (
                    <Icon className={cn('size-4', iconAccentStyles[accent])} />
                )}
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
            </div>
            <p className="mt-2 text-3xl font-bold tracking-tight tabular-nums">
                {value}
            </p>
        </div>
    );
}

export { StatCard };
export type { StatCardProps };
