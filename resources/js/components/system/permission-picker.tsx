import { ChevronDown, ChevronRight, Search } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { groupLabel } from '@/lib/system';
import { cn } from '@/lib/utils';
import type { GroupedPermissions, PermissionData } from '@/types/system.d';

type PermissionPickerProps = {
    groupedPermissions: GroupedPermissions;
    selectedIds: number[];
    onChange: (ids: number[]) => void;
    className?: string;
};

/**
 * PermissionPicker — grouped, searchable, collapsible permission selector.
 * Controlled component: receives selectedIds and emits onChange with the full updated set.
 */
function PermissionPicker({
    groupedPermissions,
    selectedIds,
    onChange,
    className,
}: PermissionPickerProps) {
    const [search, setSearch] = React.useState('');
    const [collapsed, setCollapsed] = React.useState<Record<string, boolean>>(
        () =>
            Object.fromEntries(
                Object.keys(groupedPermissions).map((k) => [k, true]),
            ),
    );

    const selectedSet = React.useMemo(
        () => new Set(selectedIds),
        [selectedIds],
    );

    const filteredGroups = React.useMemo(() => {
        if (!search.trim()) {
            return groupedPermissions;
        }

        const q = search.toLowerCase();
        const result: GroupedPermissions = {};

        for (const [key, perms] of Object.entries(groupedPermissions)) {
            const matched = perms.filter(
                (p) =>
                    p.name.toLowerCase().includes(q) ||
                    (p.display_name?.toLowerCase().includes(q) ?? false),
            );

            if (matched.length > 0) {
                result[key] = matched;
            }
        }

        return result;
    }, [groupedPermissions, search]);

    function togglePermission(id: number) {
        if (selectedSet.has(id)) {
            onChange(selectedIds.filter((sid) => sid !== id));
        } else {
            onChange([...selectedIds, id]);
        }
    }

    function toggleGroup(perms: PermissionData[]) {
        const groupIds = perms.map((p) => p.id);
        const allSelected = groupIds.every((id) => selectedSet.has(id));

        if (allSelected) {
            onChange(selectedIds.filter((id) => !groupIds.includes(id)));
        } else {
            const toAdd = groupIds.filter((id) => !selectedSet.has(id));
            onChange([...selectedIds, ...toAdd]);
        }
    }

    function toggleCollapse(key: string) {
        setCollapsed((prev) => ({ ...prev, [key]: !prev[key] }));
    }

    function expandAll() {
        setCollapsed(
            Object.fromEntries(
                Object.keys(filteredGroups).map((k) => [k, false]),
            ),
        );
    }

    function collapseAll() {
        setCollapsed(
            Object.fromEntries(
                Object.keys(filteredGroups).map((k) => [k, true]),
            ),
        );
    }

    const totalSelected = selectedIds.length;

    return (
        <div
            data-slot="permission-picker"
            className={cn('space-y-3', className)}
        >
            {/* Header */}
            <div className="flex items-center justify-between">
                <Label className="text-sm font-medium">Permisos</Label>
                {totalSelected > 0 && (
                    <Badge variant="secondary" className="tabular-nums">
                        {totalSelected} seleccionado
                        {totalSelected !== 1 ? 's' : ''}
                    </Badge>
                )}
            </div>

            {/* Search */}
            <div className="relative">
                <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    placeholder="Buscar permisos…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="pl-8"
                />
            </div>

            {/* Expand / Collapse all */}
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    className="text-xs text-muted-foreground underline-offset-2 hover:underline"
                    onClick={expandAll}
                >
                    Expandir todo
                </button>
                <span className="text-xs text-muted-foreground">·</span>
                <button
                    type="button"
                    className="text-xs text-muted-foreground underline-offset-2 hover:underline"
                    onClick={collapseAll}
                >
                    Colapsar todo
                </button>
            </div>

            {/* Groups */}
            <div className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                {Object.entries(filteredGroups).length === 0 && (
                    <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                        Sin resultados para &ldquo;{search}&rdquo;
                    </p>
                )}

                {Object.entries(filteredGroups).map(([groupKey, perms]) => {
                    const isCollapsed = collapsed[groupKey] ?? false;
                    const groupIds = perms.map((p) => p.id);
                    const allGroupSelected = groupIds.every((id) =>
                        selectedSet.has(id),
                    );
                    const someGroupSelected =
                        groupIds.some((id) => selectedSet.has(id)) &&
                        !allGroupSelected;
                    const selectedCount = perms.filter((p) =>
                        selectedSet.has(p.id),
                    ).length;

                    return (
                        <div key={groupKey} className="bg-background">
                            {/* Group header row */}
                            <div className="flex items-center gap-3 bg-muted/30 px-4 py-3 transition-colors hover:bg-muted/50">
                                <Checkbox
                                    id={`group-${groupKey}`}
                                    checked={
                                        allGroupSelected
                                            ? true
                                            : someGroupSelected
                                              ? 'indeterminate'
                                              : false
                                    }
                                    onCheckedChange={() => toggleGroup(perms)}
                                    aria-label={`Seleccionar todo en ${groupKey}`}
                                />
                                <button
                                    type="button"
                                    className="flex flex-1 items-center gap-2 text-left"
                                    onClick={() => toggleCollapse(groupKey)}
                                >
                                    <span className="text-sm font-medium">
                                        {groupLabel(groupKey)}
                                    </span>
                                    <Badge
                                        variant={
                                            selectedCount > 0
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        className="ml-auto tabular-nums"
                                    >
                                        {selectedCount}/{perms.length}
                                    </Badge>
                                    {isCollapsed ? (
                                        <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform" />
                                    ) : (
                                        <ChevronDown className="size-4 shrink-0 text-muted-foreground transition-transform" />
                                    )}
                                </button>
                            </div>

                            {/* Permission rows */}
                            {!isCollapsed && (
                                <div className="divide-y divide-border/50 border-t border-border">
                                    {perms.map((perm) => (
                                        <div
                                            key={perm.id}
                                            className="flex items-start gap-3 px-4 py-2.5 transition-colors hover:bg-muted/20"
                                        >
                                            <Checkbox
                                                id={`perm-${perm.id}`}
                                                checked={selectedSet.has(
                                                    perm.id,
                                                )}
                                                onCheckedChange={() =>
                                                    togglePermission(perm.id)
                                                }
                                                className="mt-0.5"
                                            />
                                            <Label
                                                htmlFor={`perm-${perm.id}`}
                                                className="cursor-pointer text-sm font-medium"
                                            >
                                                {perm.display_name ?? perm.name}
                                            </Label>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export { PermissionPicker };
export type { PermissionPickerProps };
