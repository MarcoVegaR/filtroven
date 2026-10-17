import {
    CalendarRange,
    Check,
    ChevronsUpDown,
    ChevronDown,
    Download,
    Filter,
    Search,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AuditFilterOption,
    AuditFilterOptions,
    AuditFilters,
} from '@/types';

type AuditFiltersProps = {
    filterOptions: AuditFilterOptions;
    onApply: (filters: AuditFilters) => void;
    onClear: () => void;
    onResetDraft: () => void;
    draft: AuditFilters;
    isDraftDirty: boolean;
    onDraftChange: <K extends keyof AuditFilters>(
        key: K,
        value: AuditFilters[K],
    ) => void;
    summary: {
        total: number;
        from: string;
        to: string;
        advancedActiveCount: number;
    };
    hasAppliedFilters: boolean;
    appliedFilters: AppliedFilter[];
    onRemoveFilter: (
        key: 'source' | 'user_id' | 'event' | 'auditable_type' | 'auditable_id',
    ) => void;
    canExport?: boolean;
    exportHref?: string;
};

type AppliedFilter = {
    key: 'source' | 'user_id' | 'event' | 'auditable_type' | 'auditable_id';
    label: string;
    value: string;
};

type SearchableFilterSelectProps = {
    label: string;
    allLabel: string;
    emptyMessage: string;
    searchPlaceholder: string;
    triggerAriaLabel: string;
    searchAriaLabel: string;
    options: AuditFilterOption[];
    value?: string;
    onValueChange: (value?: string) => void;
};

function SearchableFilterSelect({
    label,
    allLabel,
    emptyMessage,
    searchPlaceholder,
    triggerAriaLabel,
    searchAriaLabel,
    options,
    value,
    onValueChange,
}: SearchableFilterSelectProps) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');

    const selectedOption = useMemo(() => {
        if (!value) {
            return null;
        }

        return options.find((option) => option.value === value) ?? null;
    }, [options, value]);

    const filteredOptions = useMemo(() => {
        const query = search.trim().toLowerCase();

        if (!query) {
            return options;
        }

        return options.filter((option) => {
            return (
                option.label.toLowerCase().includes(query) ||
                option.value.toLowerCase().includes(query)
            );
        });
    }, [options, search]);

    return (
        <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
                {label}
            </label>
            <DropdownMenu
                open={open}
                onOpenChange={(nextOpen) => {
                    setOpen(nextOpen);

                    if (!nextOpen) {
                        setSearch('');
                    }
                }}
            >
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full justify-between font-normal"
                        aria-label={triggerAriaLabel}
                    >
                        <span className="truncate text-left">
                            {selectedOption?.label ?? value ?? allLabel}
                        </span>
                        <ChevronsUpDown className="ml-2 size-3.5 shrink-0 opacity-50" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    className="w-72 max-w-[calc(100vw-2rem)]"
                >
                    <div className="px-2 py-1.5">
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder={searchPlaceholder}
                                aria-label={searchAriaLabel}
                                className="h-8 pl-7"
                                onKeyDown={(e) => e.stopPropagation()}
                            />
                        </div>
                    </div>
                    <DropdownMenuSeparator />
                    <div className="max-h-56 overflow-y-auto">
                        <DropdownMenuItem
                            onSelect={() => {
                                onValueChange(undefined);
                                setOpen(false);
                            }}
                        >
                            {!value && <Check className="size-3.5" />}
                            <span className={!value ? '' : 'ml-5.5'}>
                                {allLabel}
                            </span>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        {filteredOptions.length === 0 ? (
                            <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                                {emptyMessage}
                            </p>
                        ) : (
                            filteredOptions.map((option) => (
                                <DropdownMenuItem
                                    key={option.value}
                                    onSelect={() => {
                                        onValueChange(option.value);
                                        setOpen(false);
                                    }}
                                >
                                    {value === option.value && (
                                        <Check className="size-3.5" />
                                    )}
                                    <span
                                        className={
                                            value === option.value
                                                ? ''
                                                : 'ml-5.5'
                                        }
                                    >
                                        {option.label}
                                    </span>
                                </DropdownMenuItem>
                            ))
                        )}
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

export function AuditFiltersPanel({
    filterOptions,
    onApply,
    onClear,
    onResetDraft,
    draft,
    isDraftDirty,
    onDraftChange,
    summary,
    hasAppliedFilters,
    appliedFilters,
    onRemoveFilter,
    canExport = false,
    exportHref,
}: AuditFiltersProps) {
    const isSecuritySource = draft.source === 'security';
    const [advancedOpen, setAdvancedOpen] = useState(
        summary.advancedActiveCount > 0,
    );
    const dateRangeLabel = useMemo(() => {
        return `${summary.from} a ${summary.to}`;
    }, [summary.from, summary.to]);

    function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        onApply(draft);
    }

    return (
        <Collapsible open={advancedOpen} onOpenChange={setAdvancedOpen}>
            <form onSubmit={handleSubmit} className="overflow-hidden">
                <div className="border-b bg-linear-to-b from-background to-muted/20 px-4 py-4 sm:px-5">
                    <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div className="flex items-center gap-2 text-sm font-medium tracking-tight text-foreground">
                            <Filter className="size-4 text-primary" />
                            <span>Filtros</span>
                        </div>

                        <div className="flex flex-wrap items-center gap-2 xl:justify-end">
                            <CollapsibleTrigger asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="group"
                                >
                                    <SlidersHorizontal className="size-4" />
                                    Filtros avanzados
                                    {summary.advancedActiveCount > 0 && (
                                        <Badge
                                            variant="secondary"
                                            className="rounded-full px-2 py-0 text-[11px]"
                                        >
                                            {summary.advancedActiveCount}
                                        </Badge>
                                    )}
                                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" />
                                </Button>
                            </CollapsibleTrigger>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={onResetDraft}
                                disabled={!isDraftDirty}
                            >
                                Restablecer edición
                            </Button>
                            <Button type="submit" size="sm">
                                Aplicar filtros
                            </Button>
                        </div>
                    </div>

                    <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">
                                Desde
                            </label>
                            <div className="relative">
                                <CalendarRange className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    type="date"
                                    value={draft.from}
                                    onChange={(e) =>
                                        onDraftChange('from', e.target.value)
                                    }
                                    className="pl-9"
                                />
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">
                                Hasta
                            </label>
                            <div className="relative">
                                <CalendarRange className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    type="date"
                                    value={draft.to}
                                    onChange={(e) =>
                                        onDraftChange('to', e.target.value)
                                    }
                                    className="pl-9"
                                />
                            </div>
                        </div>

                        <SearchableFilterSelect
                            label="Actor"
                            allLabel="Todos"
                            emptyMessage="No hay actores que coincidan."
                            searchPlaceholder="Buscar actor…"
                            triggerAriaLabel="Filtrar por actor"
                            searchAriaLabel="Buscar actor"
                            options={filterOptions.users}
                            value={draft.user_id}
                            onValueChange={(value) =>
                                onDraftChange('user_id', value)
                            }
                        />

                        <SearchableFilterSelect
                            label="Evento"
                            allLabel="Todos"
                            emptyMessage="No hay eventos que coincidan."
                            searchPlaceholder="Buscar evento…"
                            triggerAriaLabel="Filtrar por evento"
                            searchAriaLabel="Buscar evento"
                            options={filterOptions.events}
                            value={draft.event}
                            onValueChange={(value) =>
                                onDraftChange('event', value)
                            }
                        />

                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">
                                ID entidad
                            </label>
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    type="number"
                                    min="1"
                                    placeholder="Ej. 42"
                                    value={draft.auditable_id ?? ''}
                                    onChange={(e) =>
                                        onDraftChange(
                                            'auditable_id',
                                            e.target.value || undefined,
                                        )
                                    }
                                    disabled={isSecuritySource}
                                    className="pl-9"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <CollapsibleContent className="border-b bg-muted/10">
                    <div className="grid gap-3 px-4 py-4 sm:px-5 md:grid-cols-2">
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">
                                Fuente
                            </label>
                            <Select
                                value={draft.source}
                                onValueChange={(v) =>
                                    onDraftChange(
                                        'source',
                                        v as AuditFilters['source'],
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Fuente" />
                                </SelectTrigger>
                                <SelectContent>
                                    {filterOptions.sources.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={s.value}
                                        >
                                            {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">
                                Tipo de entidad
                            </label>
                            <Select
                                value={draft.auditable_type ?? 'all'}
                                onValueChange={(v) =>
                                    onDraftChange(
                                        'auditable_type',
                                        v === 'all' ? undefined : v,
                                    )
                                }
                                disabled={isSecuritySource}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Todas" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todas</SelectItem>
                                    {filterOptions.auditableTypes.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </CollapsibleContent>

                <div className="border-t border-border/60 bg-background px-4 py-3 sm:px-5">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div className="flex flex-1 flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                            <div className="flex items-baseline gap-2 whitespace-nowrap">
                                <span className="text-2xl font-semibold tracking-tight tabular-nums">
                                    {summary.total.toLocaleString('es-ES')}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    resultado{summary.total === 1 ? '' : 's'}
                                </span>
                            </div>

                            <div
                                className="h-5 w-px bg-border/70"
                                aria-hidden="true"
                            />

                            <p className="text-sm whitespace-nowrap text-muted-foreground">
                                {dateRangeLabel}
                            </p>

                            {hasAppliedFilters && (
                                <>
                                    <div
                                        className="h-5 w-px bg-border/70"
                                        aria-hidden="true"
                                    />
                                    <div className="flex flex-wrap items-center gap-2">
                                        {appliedFilters.map((filter) => (
                                            <Badge
                                                key={filter.key}
                                                variant="outline"
                                                className="h-8 rounded-full border-border/70 bg-muted/30 px-3 text-xs"
                                            >
                                                <span className="text-muted-foreground">
                                                    {filter.label}:
                                                </span>
                                                <span>{filter.value}</span>
                                                <button
                                                    type="button"
                                                    className="-mr-1 inline-flex size-4 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                    onClick={() =>
                                                        onRemoveFilter(
                                                            filter.key,
                                                        )
                                                    }
                                                    aria-label={`Quitar filtro ${filter.label}`}
                                                >
                                                    <X className="size-3" />
                                                </button>
                                            </Badge>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>

                        <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                            {hasAppliedFilters && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={onClear}
                                >
                                    Limpiar filtros activos
                                </Button>
                            )}

                            {canExport && exportHref ? (
                                <Button asChild variant="outline" size="sm">
                                    <a href={exportHref}>
                                        <Download className="size-4" />
                                        Exportar vista actual
                                    </a>
                                </Button>
                            ) : null}
                        </div>
                    </div>
                </div>
            </form>
        </Collapsible>
    );
}
