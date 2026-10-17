import { cn } from '@/lib/utils';

type ChangesTableProps = {
    oldValues: Record<string, unknown>;
    newValues: Record<string, unknown>;
};

function formatValue(value: unknown): string {
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

export function ChangesTable({ oldValues, newValues }: ChangesTableProps) {
    const allKeys = [
        ...new Set([...Object.keys(oldValues), ...Object.keys(newValues)]),
    ];

    if (allKeys.length === 0) {
        return (
            <p className="py-6 text-center text-sm text-muted-foreground">
                Sin cambios registrados para este evento.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b bg-muted/40">
                        <th className="px-4 py-2.5 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Atributo
                        </th>
                        <th className="px-4 py-2.5 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Anterior
                        </th>
                        <th className="px-4 py-2.5 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Nuevo
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {allKeys.map((key) => {
                        const oldVal = oldValues[key];
                        const newVal = newValues[key];
                        const changed =
                            key in oldValues &&
                            key in newValues &&
                            formatValue(oldVal) !== formatValue(newVal);

                        return (
                            <tr
                                key={key}
                                className={cn(
                                    changed &&
                                        'bg-primary/[0.03] dark:bg-primary/[0.06]',
                                )}
                            >
                                <td className="px-4 py-2.5 font-mono text-xs font-medium text-muted-foreground">
                                    {key}
                                </td>
                                <td className="px-4 py-2.5">
                                    {key in oldValues ? (
                                        <ValueCell
                                            value={oldVal}
                                            highlight={changed}
                                            variant="old"
                                        />
                                    ) : (
                                        <span className="text-muted-foreground/50">
                                            —
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-2.5">
                                    {key in newValues ? (
                                        <ValueCell
                                            value={newVal}
                                            highlight={changed}
                                            variant="new"
                                        />
                                    ) : (
                                        <span className="text-muted-foreground/50">
                                            —
                                        </span>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function ValueCell({
    value,
    highlight,
    variant,
}: {
    value: unknown;
    highlight: boolean;
    variant: 'old' | 'new';
}) {
    const formatted = formatValue(value);
    const isLong = formatted.length > 80;

    return (
        <span
            className={cn(
                'text-sm',
                highlight &&
                    variant === 'old' &&
                    'text-rose-600 line-through decoration-rose-300 dark:text-rose-400',
                highlight &&
                    variant === 'new' &&
                    'font-medium text-emerald-700 dark:text-emerald-400',
            )}
        >
            {isLong ? (
                <pre className="max-h-32 overflow-auto rounded bg-muted/50 px-2 py-1 font-mono text-xs whitespace-pre-wrap">
                    {formatted}
                </pre>
            ) : (
                formatted
            )}
        </span>
    );
}
