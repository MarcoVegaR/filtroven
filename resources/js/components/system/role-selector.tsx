import { Search } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { RoleData } from '@/types/system.d';

type RoleSelectorProps = {
    roles: RoleData[];
    selectedIds: number[];
    onChange: (ids: number[]) => void;
    className?: string;
};

/**
 * RoleSelector — multi-select checkbox list for active roles.
 * Controlled component: selectedIds in, onChange out.
 */
function RoleSelector({
    roles,
    selectedIds,
    onChange,
    className,
}: RoleSelectorProps) {
    const [search, setSearch] = React.useState('');

    const selectedSet = React.useMemo(
        () => new Set(selectedIds),
        [selectedIds],
    );

    const filteredRoles = React.useMemo(() => {
        if (!search.trim()) {
            return roles;
        }

        const q = search.toLowerCase();

        return roles.filter(
            (r) =>
                r.name.toLowerCase().includes(q) ||
                (r.display_name?.toLowerCase().includes(q) ?? false),
        );
    }, [roles, search]);

    function toggle(id: number) {
        if (selectedSet.has(id)) {
            onChange(selectedIds.filter((sid) => sid !== id));
        } else {
            onChange([...selectedIds, id]);
        }
    }

    return (
        <div data-slot="role-selector" className={cn('space-y-2', className)}>
            {roles.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No hay roles disponibles.
                </p>
            )}

            {roles.length > 0 && (
                <div className="relative">
                    <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        placeholder="Buscar roles…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="pl-8"
                    />
                </div>
            )}

            <div className="max-h-80 divide-y divide-border overflow-y-auto rounded-xl border border-border [contain:paint]">
                {filteredRoles.length === 0 && (
                    <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                        Sin resultados para &ldquo;{search}&rdquo;
                    </p>
                )}
                {filteredRoles.map((role) => (
                    <div
                        key={role.id}
                        className={cn(
                            'flex items-start gap-3 px-4 py-3 transition-colors',
                            !role.is_active
                                ? 'bg-muted/30'
                                : 'hover:bg-muted/20',
                        )}
                    >
                        <Checkbox
                            id={`role-${role.id}`}
                            checked={selectedSet.has(role.id)}
                            onCheckedChange={() => toggle(role.id)}
                            disabled={!role.is_active}
                            className="mt-0.5"
                        />
                        <Label
                            htmlFor={`role-${role.id}`}
                            className={cn(
                                'flex flex-1 cursor-pointer flex-col gap-0.5',
                                !role.is_active &&
                                    'cursor-not-allowed opacity-60',
                            )}
                        >
                            <span className="flex items-center gap-2 text-sm font-medium">
                                {role.display_name ?? role.name}
                                {!role.is_active && (
                                    <Badge
                                        variant="outline"
                                        className="text-xs"
                                    >
                                        Inactivo
                                    </Badge>
                                )}
                            </span>
                            {role.description && (
                                <span className="text-xs text-muted-foreground">
                                    {role.description}
                                </span>
                            )}
                        </Label>
                    </div>
                ))}
            </div>

            {selectedIds.length > 0 && (
                <p className="text-xs text-muted-foreground">
                    {selectedIds.length} rol
                    {selectedIds.length !== 1 ? 'es' : ''} seleccionado
                    {selectedIds.length !== 1 ? 's' : ''}
                </p>
            )}
        </div>
    );
}

export { RoleSelector };
export type { RoleSelectorProps };
