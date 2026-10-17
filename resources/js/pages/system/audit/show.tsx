import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Clock3,
    Globe,
    ScrollText,
    ShieldEllipsis,
    UserRound,
} from 'lucide-react';

import { index } from '@/actions/App/Http/Controllers/System/AuditController';
import { AuditSourceBadge } from '@/components/system/audit-source-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { auditableTypeLabel } from '@/lib/system';
import type { AuditDetail, BreadcrumbItem } from '@/types';

import { ChangesTable } from './components/changes-table';
import { MetadataDisplay } from './components/metadata-display';

type Props = {
    event: AuditDetail;
};

function formatExact(value: string | null): string {
    if (!value) {
        return 'Sin fecha';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('es-ES', {
        dateStyle: 'full',
        timeStyle: 'medium',
    }).format(date);
}

function formatRelative(value: string | null): string {
    if (!value) {
        return 'Sin referencia';
    }

    const date = new Date(value);
    const diffMin = Math.round((date.getTime() - Date.now()) / 60_000);
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

export default function AuditShow({ event }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Auditoría', href: index.url() },
        { title: 'Detalle', href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Detalle de auditoría" />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <div>
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="-ml-2 gap-1.5"
                    >
                        <Link href={index.url()}>
                            <ArrowLeft className="size-4" />
                            Volver
                        </Link>
                    </Button>
                </div>

                <Card className="gap-0 py-0">
                    <div className="flex flex-col gap-6 p-6 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <AuditSourceBadge
                                    source={event.source}
                                    label={event.source_label}
                                />
                                <Badge variant="outline">
                                    {event.event_label}
                                </Badge>
                                <Badge
                                    variant="secondary"
                                    className="tabular-nums"
                                >
                                    #{event.source_record_id}
                                </Badge>
                            </div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Detalle de auditoría
                            </h1>
                            <p className="max-w-2xl text-sm text-muted-foreground">
                                Evento{' '}
                                <span className="font-medium text-foreground">
                                    {event.event_label}
                                </span>{' '}
                                registrado para{' '}
                                <span className="font-medium text-foreground">
                                    {event.subject_label ?? 'sin entidad'}
                                </span>{' '}
                                por{' '}
                                <span className="font-medium text-foreground">
                                    {event.actor_name ?? 'Sistema'}
                                </span>
                                .
                            </p>
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                            <Clock3 className="size-3.5" />
                                            {formatRelative(event.timestamp)}
                                        </p>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {formatExact(event.timestamp)}
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                    </div>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm font-semibold tracking-wide uppercase">
                                <UserRound className="size-4" />
                                Contexto común
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Actor
                                </p>
                                {event.actor_href ? (
                                    <Link
                                        href={event.actor_href}
                                        className="font-medium hover:underline"
                                    >
                                        {event.actor_name ?? 'Sistema'}
                                    </Link>
                                ) : (
                                    <p className="font-medium">
                                        {event.actor_name ?? 'Sistema'}
                                    </p>
                                )}
                            </div>

                            <div>
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Entidad
                                </p>
                                {event.subject_href ? (
                                    <Link
                                        href={event.subject_href}
                                        className="font-medium hover:underline"
                                    >
                                        {event.subject_label ?? 'Sin entidad'}
                                    </Link>
                                ) : (
                                    <p className="font-medium">
                                        {event.subject_label ?? 'Sin entidad'}
                                    </p>
                                )}
                                {event.subject_type && (
                                    <p className="text-sm text-muted-foreground">
                                        {auditableTypeLabel(event.subject_type)}
                                    </p>
                                )}
                            </div>

                            <Separator />

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        IP
                                    </p>
                                    <div className="mt-1 flex items-center gap-2 text-sm font-medium">
                                        <Globe className="size-4 text-muted-foreground" />
                                        {event.ip_address ?? 'No registrada'}
                                    </div>
                                </div>
                                <div>
                                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        Origen
                                    </p>
                                    <div className="mt-1">
                                        <AuditSourceBadge
                                            source={event.source}
                                            label={event.source_label}
                                        />
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm font-semibold tracking-wide uppercase">
                                <ShieldEllipsis className="size-4" />
                                {event.source === 'model'
                                    ? 'Metadatos del registro'
                                    : 'Metadatos de seguridad'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {event.source === 'model' ? (
                                <>
                                    <div>
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Tipo
                                        </p>
                                        <p className="font-medium">
                                            {auditableTypeLabel(
                                                event.subject_type,
                                            )}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            ID entidad
                                        </p>
                                        <p className="font-medium">
                                            {event.subject_id ?? '—'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            URL
                                        </p>
                                        <p className="text-sm break-all">
                                            {event.url ?? 'No registrada'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            User agent
                                        </p>
                                        <p className="text-sm">
                                            {event.user_agent ??
                                                'No disponible'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Tags
                                        </p>
                                        <p className="text-sm">
                                            {event.tags ?? 'Sin tags'}
                                        </p>
                                    </div>
                                </>
                            ) : (
                                <>
                                    <div>
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Correlation ID
                                        </p>
                                        <p className="font-mono text-sm font-medium">
                                            {event.correlation_id ??
                                                'No asociado'}
                                        </p>
                                    </div>
                                    <Separator />
                                    {event.metadata.length === 0 ? (
                                        <div className="rounded-xl border border-dashed bg-muted/20 px-4 py-5 text-sm text-muted-foreground">
                                            Este evento no registró metadatos
                                            adicionales más allá del
                                            identificador de correlación.
                                        </div>
                                    ) : (
                                        <MetadataDisplay
                                            items={event.metadata}
                                        />
                                    )}
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {event.source === 'model' && (
                    <Card className="gap-0 overflow-hidden py-0">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm font-semibold tracking-wide uppercase">
                                <ScrollText className="size-4" />
                                Comparación de valores
                            </CardTitle>
                        </CardHeader>
                        <ChangesTable
                            oldValues={event.old_values}
                            newValues={event.new_values}
                        />
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
