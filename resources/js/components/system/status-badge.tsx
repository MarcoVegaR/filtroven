import { cn } from '@/lib/utils';

type StatusBadgeProps = {
    active: boolean;
    activeLabel?: string;
    inactiveLabel?: string;
    className?: string;
};

/**
 * StatusBadge — reusable active/inactive indicator.
 * Uses semantic color tokens so it adapts to dark mode automatically.
 */
function StatusBadge({
    active,
    activeLabel = 'Activo',
    inactiveLabel = 'Inactivo',
    className,
}: StatusBadgeProps) {
    return (
        <span
            data-slot="status-badge"
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
                active
                    ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950/40 dark:text-green-400'
                    : 'border-border bg-muted text-muted-foreground',
                className,
            )}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    active
                        ? 'bg-green-500 dark:bg-green-400'
                        : 'bg-muted-foreground/50',
                )}
                aria-hidden="true"
            />
            {active ? activeLabel : inactiveLabel}
        </span>
    );
}

export { StatusBadge };
export type { StatusBadgeProps };
