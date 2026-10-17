import { Badge } from '@/components/ui/badge';

type AuditSourceBadgeProps = {
    source: 'model' | 'security';
    label?: string;
};

export function AuditSourceBadge({ source, label }: AuditSourceBadgeProps) {
    const resolvedLabel =
        label ?? (source === 'model' ? 'Modelos' : 'Seguridad');

    return (
        <Badge
            variant={source === 'model' ? 'secondary' : 'outline'}
            className={
                source === 'model'
                    ? 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-300'
                    : 'border-cyan-200 bg-cyan-50 text-cyan-700 dark:border-cyan-800 dark:bg-cyan-950/40 dark:text-cyan-300'
            }
        >
            {resolvedLabel}
        </Badge>
    );
}
