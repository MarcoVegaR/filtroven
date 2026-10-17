import type { AuditMetadataItem } from '@/types';

type MetadataDisplayProps = {
    items: AuditMetadataItem[];
};

function formatMetadataValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Sí' : 'No';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value, null, 2);
    }

    return String(value);
}

export function MetadataDisplay({ items }: MetadataDisplayProps) {
    if (items.length === 0) {
        return (
            <p className="py-4 text-sm text-muted-foreground">
                No hay metadatos adicionales para este evento.
            </p>
        );
    }

    return (
        <dl className="divide-y">
            {items.map((item) => {
                const formatted = formatMetadataValue(item.value);
                const isLong = formatted.length > 100;

                return (
                    <div
                        key={item.key}
                        className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:gap-6"
                    >
                        <dt className="w-40 shrink-0 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {item.label}
                        </dt>
                        <dd className="text-sm">
                            {isLong ? (
                                <pre className="max-h-32 overflow-auto rounded bg-muted/50 px-2 py-1 font-mono text-xs whitespace-pre-wrap">
                                    {formatted}
                                </pre>
                            ) : (
                                formatted
                            )}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}
