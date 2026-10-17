import { Head, router } from '@inertiajs/react';
import { Search, ShieldAlert } from 'lucide-react';
import { useState } from 'react';

import { index as permissionsIndex } from '@/actions/App/Http/Controllers/System/PermissionController';
import { index as rolesIndex } from '@/actions/App/Http/Controllers/System/RoleController';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Toolbar, ToolbarGroup } from '@/components/ui/toolbar';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, GroupedPermissions } from '@/types';

const groupLabels: Record<string, string> = {
    'system.roles': 'Gestión de Roles',
    'system.users': 'Gestión de Usuarios',
    'system.permissions': 'Gestión de Permisos',
};

function groupLabel(key: string): string {
    return groupLabels[key] ?? key;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Roles', href: rolesIndex.url() },
    { title: 'Permisos', href: permissionsIndex.url() },
];

type Props = {
    groupedPermissions: GroupedPermissions;
    filters: {
        search?: string;
    };
};

export default function PermissionsIndex({
    groupedPermissions,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    function handleSearchSubmit(e: React.FormEvent) {
        e.preventDefault();
        router.get(
            permissionsIndex.url(),
            { search: search || undefined },
            { preserveState: true, replace: true },
        );
    }

    const hasPermissions = Object.values(groupedPermissions).some(
        (perms) => perms.length > 0,
    );
    const totalPermissions = Object.values(groupedPermissions).reduce(
        (sum, perms) => sum + perms.length,
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Permisos" />

            <div className="space-y-4 px-4 py-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Catálogo de permisos
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Lista de todos los permisos registrados en el
                            sistema. Solo lectura.
                        </p>
                    </div>
                    {totalPermissions > 0 && (
                        <Badge variant="secondary" className="tabular-nums">
                            {totalPermissions} permisos
                        </Badge>
                    )}
                </div>

                <Toolbar>
                    <ToolbarGroup>
                        <form
                            onSubmit={handleSearchSubmit}
                            className="flex items-center gap-2"
                        >
                            <div className="relative">
                                <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    type="search"
                                    placeholder="Buscar permisos…"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="w-72 pl-8"
                                />
                            </div>
                        </form>
                    </ToolbarGroup>
                </Toolbar>

                {!hasPermissions ? (
                    <EmptyState
                        icon={ShieldAlert}
                        title="Sin permisos"
                        description={
                            search
                                ? `No hay permisos que coincidan con "${search}".`
                                : 'No hay permisos registrados en el sistema.'
                        }
                    />
                ) : (
                    <div className="space-y-6">
                        {Object.entries(groupedPermissions).map(
                            ([group, perms]) => (
                                <div key={group}>
                                    <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold tracking-wide uppercase">
                                        {groupLabel(group)}
                                        <Badge
                                            variant="outline"
                                            className="tabular-nums"
                                        >
                                            {perms.length}
                                        </Badge>
                                    </h2>
                                    <div className="rounded-md border">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>
                                                        Nombre para mostrar
                                                    </TableHead>
                                                    <TableHead>
                                                        Clave técnica
                                                    </TableHead>
                                                    <TableHead>
                                                        Descripción
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {perms.map((perm) => (
                                                    <TableRow key={perm.id}>
                                                        <TableCell className="font-medium">
                                                            {perm.display_name ??
                                                                '—'}
                                                        </TableCell>
                                                        <TableCell>
                                                            <code className="font-mono text-xs text-muted-foreground">
                                                                {perm.name}
                                                            </code>
                                                        </TableCell>
                                                        <TableCell className="text-sm text-muted-foreground">
                                                            {perm.description ??
                                                                '—'}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </div>
                            ),
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
