import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Eye,
    MoreHorizontal,
    Pencil,
    PlusCircle,
    Power,
    PowerOff,
    Search,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useCallback, useState } from 'react';

import RoleActivateController from '@/actions/App/Http/Controllers/System/RoleActivateController';
import {
    create,
    destroy,
    edit,
    index,
    show,
} from '@/actions/App/Http/Controllers/System/RoleController';
import RoleDeactivateController from '@/actions/App/Http/Controllers/System/RoleDeactivateController';
import { HelpLink } from '@/components/help/help-link';
import { PageHeader } from '@/components/system/page-header';
import { StatusBadge } from '@/components/system/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, PaginatedData, RoleData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Roles', href: index.url() }];

type Props = {
    roles: PaginatedData<RoleData>;
    filters: {
        search?: string;
        status?: string;
        sort?: string;
        direction?: string;
    };
};

function renderSortIcon(
    activeSort: string | undefined,
    direction: string | undefined,
    column: string,
): React.ReactNode {
    if (activeSort !== column) {
        return (
            <ArrowUpDown className="ml-1 inline size-3.5 text-muted-foreground/50" />
        );
    }

    return direction === 'desc' ? (
        <ArrowDown className="ml-1 inline size-3.5" />
    ) : (
        <ArrowUp className="ml-1 inline size-3.5" />
    );
}

export default function RolesIndex({ roles, filters }: Props) {
    const canCreate = useCan('system.roles.create');
    const canUpdate = useCan('system.roles.update');
    const canDelete = useCan('system.roles.delete');

    const [search, setSearch] = useState(filters.search ?? '');
    const [processing, setProcessing] = useState(false);
    const [deactivateTarget, setDeactivateTarget] = useState<RoleData | null>(
        null,
    );
    const [activateTarget, setActivateTarget] = useState<RoleData | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<RoleData | null>(null);

    function handleDeactivate(role: RoleData) {
        setProcessing(true);
        router.patch(
            RoleDeactivateController.url(role),
            {},
            {
                onFinish: () => {
                    setProcessing(false);
                    setDeactivateTarget(null);
                },
            },
        );
    }

    function handleActivate(role: RoleData) {
        setProcessing(true);
        router.patch(
            RoleActivateController.url(role),
            {},
            {
                onFinish: () => {
                    setProcessing(false);
                    setActivateTarget(null);
                },
            },
        );
    }

    function handleDelete(role: RoleData) {
        setProcessing(true);
        router.delete(destroy.url(role), {
            onFinish: () => {
                setProcessing(false);
                setDeleteTarget(null);
            },
        });
    }

    const applyFilter = useCallback(
        (params: Record<string, string | undefined>) => {
            router.get(
                index.url(),
                {
                    search: search || undefined,
                    sort: filters.sort,
                    direction: filters.direction,
                    status: filters.status,
                    ...params,
                },
                { preserveState: true, replace: true },
            );
        },
        [search, filters.sort, filters.direction, filters.status],
    );

    function handleSort(column: string) {
        const isCurrentSort = filters.sort === column;
        const newDirection =
            isCurrentSort && filters.direction === 'asc' ? 'desc' : 'asc';
        applyFilter({ sort: column, direction: newDirection });
    }

    function handleSearchSubmit(e: React.FormEvent) {
        e.preventDefault();
        applyFilter({ search: search || undefined });
    }

    function handleStatusChange(value: string) {
        applyFilter({ status: value === 'all' ? undefined : value });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Roles" />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <PageHeader
                    icon={ShieldCheck}
                    title="Roles"
                    description="Define los roles del sistema y configura sus permisos de acceso."
                    actions={
                        <>
                            <HelpLink
                                category="roles-and-permissions"
                                slug="manage-roles"
                            />
                            {canCreate ? (
                                <Button asChild size="sm">
                                    <Link href={create.url()}>
                                        <PlusCircle className="size-4" />
                                        Crear rol
                                    </Link>
                                </Button>
                            ) : null}
                        </>
                    }
                />

                {/* Filters */}
                <Card className="gap-0 py-0">
                    <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <form
                            onSubmit={handleSearchSubmit}
                            className="relative flex-1 sm:max-w-xs"
                        >
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                placeholder="Buscar roles…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="pl-9"
                            />
                        </form>

                        <Select
                            defaultValue={filters.status ?? 'all'}
                            onValueChange={handleStatusChange}
                        >
                            <SelectTrigger className="w-36">
                                <SelectValue placeholder="Estado" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todos</SelectItem>
                                <SelectItem value="active">Activos</SelectItem>
                                <SelectItem value="inactive">
                                    Inactivos
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </Card>

                {roles.data.length === 0 ? (
                    <Card className="py-0">
                        <EmptyState
                            icon={ShieldCheck}
                            title="Sin roles"
                            description="No hay roles que coincidan con los filtros aplicados."
                            action={
                                canCreate ? (
                                    <Button asChild size="sm">
                                        <Link href={create.url()}>
                                            <PlusCircle className="size-4" />
                                            Crear rol
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                        />
                    </Card>
                ) : (
                    <>
                        <Card className="gap-0 overflow-hidden py-0">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                                        <TableHead>
                                            <button
                                                type="button"
                                                className="inline-flex items-center hover:text-foreground"
                                                onClick={() =>
                                                    handleSort('name')
                                                }
                                            >
                                                Nombre
                                                {renderSortIcon(
                                                    filters.sort,
                                                    filters.direction,
                                                    'name',
                                                )}
                                            </button>
                                        </TableHead>
                                        <TableHead>Estado</TableHead>
                                        <TableHead className="text-center">
                                            <button
                                                type="button"
                                                className="inline-flex items-center hover:text-foreground"
                                                onClick={() =>
                                                    handleSort(
                                                        'permissions_count',
                                                    )
                                                }
                                            >
                                                Permisos
                                                {renderSortIcon(
                                                    filters.sort,
                                                    filters.direction,
                                                    'permissions_count',
                                                )}
                                            </button>
                                        </TableHead>
                                        <TableHead className="text-center">
                                            <button
                                                type="button"
                                                className="inline-flex items-center hover:text-foreground"
                                                onClick={() =>
                                                    handleSort('users_count')
                                                }
                                            >
                                                Usuarios
                                                {renderSortIcon(
                                                    filters.sort,
                                                    filters.direction,
                                                    'users_count',
                                                )}
                                            </button>
                                        </TableHead>
                                        <TableHead className="w-12" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {roles.data.map((role) => (
                                        <TableRow
                                            key={role.id}
                                            className="group transition-colors"
                                        >
                                            <TableCell>
                                                <Link
                                                    href={show.url(role)}
                                                    className="flex flex-col gap-0.5"
                                                >
                                                    <span className="font-medium hover:underline">
                                                        {role.display_name ??
                                                            role.name}
                                                    </span>
                                                    {role.display_name && (
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {role.name}
                                                        </span>
                                                    )}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    active={role.is_active}
                                                />
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge
                                                    variant="secondary"
                                                    className="tabular-nums"
                                                >
                                                    {role.permissions_count ??
                                                        0}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge
                                                    variant="outline"
                                                    className="tabular-nums"
                                                >
                                                    {role.users_count ?? 0}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8 opacity-0 transition-opacity group-hover:opacity-100 data-[state=open]:opacity-100"
                                                            aria-label="Acciones"
                                                        >
                                                            <MoreHorizontal className="size-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <Link
                                                                href={show.url(
                                                                    role,
                                                                )}
                                                            >
                                                                <Eye className="mr-2 size-4" />
                                                                Ver
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        {canUpdate && (
                                                            <DropdownMenuItem
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={edit.url(
                                                                        role,
                                                                    )}
                                                                >
                                                                    <Pencil className="mr-2 size-4" />
                                                                    Editar
                                                                </Link>
                                                            </DropdownMenuItem>
                                                        )}
                                                        {canUpdate && (
                                                            <>
                                                                <DropdownMenuSeparator />
                                                                {role.is_active ? (
                                                                    <DropdownMenuItem
                                                                        onSelect={() =>
                                                                            setDeactivateTarget(
                                                                                role,
                                                                            )
                                                                        }
                                                                    >
                                                                        <PowerOff className="mr-2 size-4" />
                                                                        Desactivar
                                                                    </DropdownMenuItem>
                                                                ) : (
                                                                    <DropdownMenuItem
                                                                        onSelect={() =>
                                                                            setActivateTarget(
                                                                                role,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Power className="mr-2 size-4" />
                                                                        Activar
                                                                    </DropdownMenuItem>
                                                                )}
                                                            </>
                                                        )}
                                                        {canDelete && (
                                                            <>
                                                                <DropdownMenuSeparator />
                                                                <DropdownMenuItem
                                                                    className="text-destructive focus:text-destructive"
                                                                    onSelect={() =>
                                                                        setDeleteTarget(
                                                                            role,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 className="mr-2 size-4" />
                                                                    Eliminar
                                                                </DropdownMenuItem>
                                                            </>
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>

                        <LaravelPagination
                            links={roles.links}
                            from={roles.from}
                            to={roles.to}
                            total={roles.total}
                        />
                    </>
                )}
            </div>

            <ConfirmationDialog
                open={deactivateTarget !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeactivateTarget(null);
                    }
                }}
                title="¿Desactivar rol?"
                description={`Los ${deactivateTarget?.users_count ?? 0} usuario(s) asignados a "${deactivateTarget?.display_name ?? deactivateTarget?.name}" perderán los permisos de este rol inmediatamente.`}
                confirmLabel="Desactivar"
                variant="destructive"
                onConfirm={() =>
                    deactivateTarget && handleDeactivate(deactivateTarget)
                }
                loading={processing}
            />

            <ConfirmationDialog
                open={activateTarget !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setActivateTarget(null);
                    }
                }}
                title="¿Activar rol?"
                description={`Los usuarios asignados a "${activateTarget?.display_name ?? activateTarget?.name}" recuperarán los permisos de este rol.`}
                confirmLabel="Activar"
                variant="default"
                onConfirm={() =>
                    activateTarget && handleActivate(activateTarget)
                }
                loading={processing}
            />

            <ConfirmationDialog
                open={deleteTarget !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteTarget(null);
                    }
                }}
                title="¿Eliminar rol?"
                description={`Esta acción es permanente. El rol "${deleteTarget?.display_name ?? deleteTarget?.name}" será eliminado del sistema.`}
                confirmLabel="Eliminar permanentemente"
                variant="destructive"
                onConfirm={() => deleteTarget && handleDelete(deleteTarget)}
                loading={processing}
            />
        </AppLayout>
    );
}
