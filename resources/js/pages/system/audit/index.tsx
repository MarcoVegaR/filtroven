import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, ScrollText } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import {
    index,
    show,
} from '@/actions/App/Http/Controllers/System/AuditController';
import AuditExportController from '@/actions/App/Http/Controllers/System/AuditExportController';
import { HelpLink } from '@/components/help/help-link';
import { AuditSourceBadge } from '@/components/system/audit-source-badge';
import { PageHeader } from '@/components/system/page-header';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import type {
    AuditFilterOptions,
    AuditFilters,
    AuditIndexEvent,
    BreadcrumbItem,
    PaginatedAuditEvents,
} from '@/types';

import { AuditFiltersPanel } from './components/audit-filters';

type AppliedAuditFilter = {
    key: 'source' | 'user_id' | 'event' | 'auditable_type' | 'auditable_id';
    label: string;
    value: string;
};

function isAppliedAuditFilter(
    value: AppliedAuditFilter | null,
): value is AppliedAuditFilter {
    return value !== null;
}

type Props = {
    events: PaginatedAuditEvents;
    filters: AuditFilters;
    filterOptions: AuditFilterOptions;
    hasActiveDateFilters: boolean;
};

function formatRelative(value: string | null): string {
    if (!value) {
        return 'Sin fecha';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const diffMs = date.getTime() - Date.now();
    const diffMin = Math.round(diffMs / 60_000);
    const formatter = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });

    if (Math.abs(diffMin) < 60) {
        return formatter.format(diffMin, 'minute');
    }

    const diffH = Math.round(diffMin / 60);

    if (Math.abs(diffH) < 24) {
        return formatter.format(diffH, 'hour');
    }

    return formatter.format(Math.round(diffH / 24), 'day');
}

function formatExact(value: string | null): string {
    if (!value) {
        return 'Sin fecha';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('es-ES', {
        dateStyle: 'medium',
        timeStyle: 'medium',
    }).format(date);
}

function renderSortIcon(activeSort: string, direction: string, column: string) {
    if (activeSort !== column) {
        return (
            <ArrowUpDown className="ml-1 inline size-3.5 text-muted-foreground/50" />
        );
    }

    return direction === 'asc' ? (
        <ArrowUp className="ml-1 inline size-3.5" />
    ) : (
        <ArrowDown className="ml-1 inline size-3.5" />
    );
}

export default function AuditIndex({
    events,
    filters,
    filterOptions,
    hasActiveDateFilters,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Auditoría', href: index.url() },
    ];

    const canExport = useCan('system.audit.export');
    const [draftFilters, setDraftFilters] = useState(filters);

    useEffect(() => {
        setDraftFilters(filters);
    }, [filters]);

    const exportHref = useMemo(
        () =>
            AuditExportController.url({
                query: {
                    source: filters.source,
                    from: filters.from,
                    to: filters.to,
                    user_id: filters.user_id,
                    event: filters.event,
                    auditable_type: filters.auditable_type,
                    auditable_id: filters.auditable_id,
                    sort: filters.sort,
                    direction: filters.direction,
                },
            }),
        [filters],
    );

    const activeSourceLabel = useMemo(() => {
        return (
            filterOptions.sources.find(
                (option) => option.value === filters.source,
            )?.label ?? 'Todas'
        );
    }, [filterOptions.sources, filters.source]);

    const activeEventLabel = useMemo(() => {
        if (!filters.event) {
            return 'Todos';
        }

        return (
            filterOptions.events.find(
                (option) => option.value === filters.event,
            )?.label ?? filters.event
        );
    }, [filterOptions.events, filters.event]);

    const activeUserLabel = useMemo(() => {
        if (!filters.user_id) {
            return 'Todos';
        }

        return (
            filterOptions.users.find(
                (option) => option.value === filters.user_id,
            )?.label ?? `ID ${filters.user_id}`
        );
    }, [filterOptions.users, filters.user_id]);

    const activeAuditableTypeLabel = useMemo(() => {
        if (!filters.auditable_type) {
            return null;
        }

        return (
            filterOptions.auditableTypes.find(
                (option) => option.value === filters.auditable_type,
            )?.label ?? filters.auditable_type
        );
    }, [filterOptions.auditableTypes, filters.auditable_type]);

    const appliedFilters = useMemo(
        () =>
            [
                filters.source !== 'all'
                    ? {
                          key: 'source' as const,
                          label: 'Fuente',
                          value: activeSourceLabel,
                      }
                    : null,
                filters.user_id
                    ? {
                          key: 'user_id' as const,
                          label: 'Actor',
                          value: activeUserLabel,
                      }
                    : null,
                filters.event
                    ? {
                          key: 'event' as const,
                          label: 'Evento',
                          value: activeEventLabel,
                      }
                    : null,
                activeAuditableTypeLabel
                    ? {
                          key: 'auditable_type' as const,
                          label: 'Tipo',
                          value: activeAuditableTypeLabel,
                      }
                    : null,
                filters.auditable_id
                    ? {
                          key: 'auditable_id' as const,
                          label: 'ID',
                          value: filters.auditable_id,
                      }
                    : null,
            ].filter(isAppliedAuditFilter),
        [
            activeAuditableTypeLabel,
            activeEventLabel,
            activeSourceLabel,
            activeUserLabel,
            filters.auditable_id,
            filters.event,
            filters.source,
            filters.user_id,
        ],
    );

    const hasAppliedFilters = appliedFilters.length > 0 || hasActiveDateFilters;

    const advancedActiveCount = useMemo(() => {
        return [
            filters.source !== 'all',
            Boolean(filters.auditable_type),
        ].filter(Boolean).length;
    }, [filters.auditable_type, filters.source]);

    const isDraftDirty = useMemo(() => {
        return (
            draftFilters.source !== filters.source ||
            draftFilters.from !== filters.from ||
            draftFilters.to !== filters.to ||
            draftFilters.user_id !== filters.user_id ||
            draftFilters.event !== filters.event ||
            draftFilters.auditable_type !== filters.auditable_type ||
            draftFilters.auditable_id !== filters.auditable_id
        );
    }, [draftFilters, filters]);

    function applyFilters(nextFilters: AuditFilters) {
        router.get(
            index.url(),
            {
                source: nextFilters.source,
                from: nextFilters.from,
                to: nextFilters.to,
                user_id: nextFilters.user_id || undefined,
                event: nextFilters.event || undefined,
                auditable_type:
                    nextFilters.source === 'security'
                        ? undefined
                        : nextFilters.auditable_type || undefined,
                auditable_id:
                    nextFilters.source === 'security'
                        ? undefined
                        : nextFilters.auditable_id || undefined,
                sort: nextFilters.sort,
                direction: nextFilters.direction,
            },
            { preserveState: true, replace: true },
        );
    }

    function handleDraftChange<K extends keyof AuditFilters>(
        key: K,
        value: AuditFilters[K],
    ) {
        setDraftFilters((current) => {
            const next = { ...current, [key]: value };

            if (key === 'source' && value === 'security') {
                next.auditable_type = undefined;
                next.auditable_id = undefined;
            }

            return next;
        });
    }

    function clearFilters() {
        const nextFilters: AuditFilters = {
            ...filters,
            source: 'all',
            from: '',
            to: '',
            user_id: undefined,
            event: undefined,
            auditable_type: undefined,
            auditable_id: undefined,
            sort: 'timestamp',
            direction: 'desc',
        };

        setDraftFilters(nextFilters);
        applyFilters(nextFilters);
    }

    function resetDraftFilters() {
        setDraftFilters(filters);
    }

    function removeFilter(
        key: 'source' | 'user_id' | 'event' | 'auditable_type' | 'auditable_id',
    ) {
        const nextFilters: AuditFilters = { ...filters };

        if (key === 'source') {
            nextFilters.source = 'all';
            nextFilters.auditable_type = undefined;
            nextFilters.auditable_id = undefined;
        }

        if (key === 'user_id') {
            nextFilters.user_id = undefined;
        }

        if (key === 'event') {
            nextFilters.event = undefined;
        }

        if (key === 'auditable_type') {
            nextFilters.auditable_type = undefined;
        }

        if (key === 'auditable_id') {
            nextFilters.auditable_id = undefined;
        }

        setDraftFilters(nextFilters);
        applyFilters(nextFilters);
    }

    function handleSort(column: AuditFilters['sort']) {
        const direction =
            filters.sort === column && filters.direction === 'asc'
                ? 'desc'
                : 'asc';

        applyFilters({
            ...filters,
            sort: column,
            direction,
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Auditoría" />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <PageHeader
                    icon={ScrollText}
                    title="Auditoría"
                    description="Consulta cambios de modelos y eventos de seguridad del sistema."
                    actions={
                        <HelpLink category="audit" slug="review-audit-events" />
                    }
                />

                <Card className="gap-0 py-0">
                    <AuditFiltersPanel
                        filterOptions={filterOptions}
                        onApply={applyFilters}
                        onClear={clearFilters}
                        onResetDraft={resetDraftFilters}
                        draft={draftFilters}
                        isDraftDirty={isDraftDirty}
                        onDraftChange={handleDraftChange}
                        summary={{
                            total: events.total,
                            from: filters.from,
                            to: filters.to,
                            advancedActiveCount,
                        }}
                        hasAppliedFilters={hasAppliedFilters}
                        appliedFilters={appliedFilters}
                        onRemoveFilter={removeFilter}
                        canExport={canExport}
                        exportHref={exportHref}
                    />
                </Card>

                {events.data.length === 0 ? (
                    <Card className="py-0">
                        <EmptyState
                            icon={ScrollText}
                            title="Sin eventos para mostrar"
                            description="Ajusta la ventana o los filtros activos para encontrar registros de auditoría."
                        />
                    </Card>
                ) : (
                    <>
                        <Card className="gap-0 overflow-hidden py-0">
                            <TooltipProvider>
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableHead>
                                                <button
                                                    type="button"
                                                    className="inline-flex items-center hover:text-foreground"
                                                    onClick={() =>
                                                        handleSort('timestamp')
                                                    }
                                                >
                                                    Fecha
                                                    {renderSortIcon(
                                                        filters.sort,
                                                        filters.direction,
                                                        'timestamp',
                                                    )}
                                                </button>
                                            </TableHead>
                                            <TableHead>Fuente</TableHead>
                                            <TableHead>
                                                <button
                                                    type="button"
                                                    className="inline-flex items-center hover:text-foreground"
                                                    onClick={() =>
                                                        handleSort('actor_name')
                                                    }
                                                >
                                                    Actor
                                                    {renderSortIcon(
                                                        filters.sort,
                                                        filters.direction,
                                                        'actor_name',
                                                    )}
                                                </button>
                                            </TableHead>
                                            <TableHead>Evento</TableHead>
                                            <TableHead>
                                                <button
                                                    type="button"
                                                    className="inline-flex items-center hover:text-foreground"
                                                    onClick={() =>
                                                        handleSort(
                                                            'subject_label',
                                                        )
                                                    }
                                                >
                                                    Entidad
                                                    {renderSortIcon(
                                                        filters.sort,
                                                        filters.direction,
                                                        'subject_label',
                                                    )}
                                                </button>
                                            </TableHead>
                                            <TableHead>
                                                <button
                                                    type="button"
                                                    className="inline-flex items-center hover:text-foreground"
                                                    onClick={() =>
                                                        handleSort('ip_address')
                                                    }
                                                >
                                                    IP
                                                    {renderSortIcon(
                                                        filters.sort,
                                                        filters.direction,
                                                        'ip_address',
                                                    )}
                                                </button>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {events.data.map((event) => (
                                            <EventRow
                                                key={event.id}
                                                event={event}
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            </TooltipProvider>
                        </Card>

                        <LaravelPagination
                            links={events.links}
                            from={events.from}
                            to={events.to}
                            total={events.total}
                        />
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function EventRow({ event }: { event: AuditIndexEvent }) {
    return (
        <TableRow
            className="group cursor-pointer transition-colors hover:bg-muted/40"
            onClick={() =>
                router.get(
                    show.url({
                        source: event.source,
                        id: event.source_record_id,
                    }),
                )
            }
        >
            <TableCell>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span className="text-sm font-medium">
                            {formatRelative(event.timestamp)}
                        </span>
                    </TooltipTrigger>
                    <TooltipContent side="top">
                        {formatExact(event.timestamp)}
                    </TooltipContent>
                </Tooltip>
            </TableCell>
            <TableCell>
                <AuditSourceBadge
                    source={event.source}
                    label={event.source_label}
                />
            </TableCell>
            <TableCell>
                <div className="space-y-0.5">
                    {event.actor_href ? (
                        <Link
                            href={event.actor_href}
                            className="text-sm font-medium hover:underline"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {event.actor_name ?? 'Sistema'}
                        </Link>
                    ) : (
                        <span className="text-sm font-medium">
                            {event.actor_name ?? 'Sistema'}
                        </span>
                    )}
                </div>
            </TableCell>
            <TableCell>
                <span className="text-sm">{event.event_label}</span>
            </TableCell>
            <TableCell>
                <div className="space-y-0.5">
                    {event.subject_href ? (
                        <Link
                            href={event.subject_href}
                            className="text-sm font-medium hover:underline"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {event.subject_label ?? 'Sin entidad'}
                        </Link>
                    ) : (
                        <Link
                            href={show.url({
                                source: event.source,
                                id: event.source_record_id,
                            })}
                            className="text-sm font-medium transition-colors hover:text-foreground hover:underline"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {event.subject_label ?? 'Sin entidad'}
                        </Link>
                    )}
                    {event.subject_type && (
                        <p className="text-xs text-muted-foreground">
                            {event.subject_type}
                        </p>
                    )}
                </div>
            </TableCell>
            <TableCell>
                <code className="rounded bg-muted px-2 py-1 text-xs">
                    {event.ip_address ?? '—'}
                </code>
            </TableCell>
        </TableRow>
    );
}
