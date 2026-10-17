import { Head, Link, router } from '@inertiajs/react';
import {
    Check,
    ChevronsUpDown,
    Copy,
    Download,
    Eye,
    KeyRound,
    MoreHorizontal,
    Pencil,
    PlusCircle,
    Power,
    PowerOff,
    Search,
    Sparkles,
    Trash2,
    Users,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import {
    create,
    destroy,
    edit,
    index,
    show,
} from '@/actions/App/Http/Controllers/System/UserController';
import ActivateUserController from '@/actions/App/Http/Controllers/System/Users/ActivateUserController';
import DeactivateUserController from '@/actions/App/Http/Controllers/System/Users/DeactivateUserController';
import ExportUsersController from '@/actions/App/Http/Controllers/System/Users/ExportUsersController';
import SendPasswordResetController from '@/actions/App/Http/Controllers/System/Users/SendPasswordResetController';
import { HelpLink } from '@/components/help/help-link';
import { BulkActionBar } from '@/components/system/bulk-action-bar';
import { CopilotSheet } from '@/components/system/copilot/copilot-sheet';
import { PageHeader } from '@/components/system/page-header';
import { StatusBadge } from '@/components/system/status-badge';
import { UserAvatar } from '@/components/system/user-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import type {
    BreadcrumbItem,
    PaginatedData,
    RoleData,
    UserWithRoles,
} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Usuarios', href: index.url() },
];

type Props = {
    users: PaginatedData<UserWithRoles>;
    roles: RoleData[];
    filters: {
        search?: string;
        role?: string;
        status?: string;
        sort?: string;
        direction?: string;
    };
};

function sanitizeTsvCell(value: string): string {
    return value.replace(/[\t\n\r]+/g, ' ').trim();
}

function formatCreatedAtForTsv(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('es-ES', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function buildUsersTsv(rows: UserWithRoles[]): string {
    const headers = ['Nombre', 'Correo', 'Estado', 'Roles', 'Creado'];
    const lines = rows.map((user) => {
        const roles =
            user.roles.length > 0
                ? user.roles
                      .map((role) => role.display_name ?? role.name)
                      .join(', ')
                : 'Sin roles';

        return [
            sanitizeTsvCell(user.name),
            sanitizeTsvCell(user.email),
            user.is_active ? 'Activo' : 'Inactivo',
            sanitizeTsvCell(roles),
            formatCreatedAtForTsv(user.created_at),
        ].join('\t');
    });

    return [headers.join('\t'), ...lines].join('\n');
}

export default function UsersIndex({ users, roles, filters }: Props) {
    const canCreate = useCan('system.users.create');
    const canUpdate = useCan('system.users.update');
    const canDelete = useCan('system.users.delete');
    const canExport = useCan('system.users.export');
    const canDeactivate = useCan('system.users.deactivate');
    const canSendReset = useCan('system.users.send-reset');
    const [copiedText, copy] = useClipboard();

    const [search, setSearch] = useState(filters.search ?? '');
    const [roleFilterSearch, setRoleFilterSearch] = useState('');
    const [roleDropdownOpen, setRoleDropdownOpen] = useState(false);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);
    const [deactivateTarget, setDeactivateTarget] =
        useState<UserWithRoles | null>(null);
    const [activateTarget, setActivateTarget] = useState<UserWithRoles | null>(
        null,
    );
    const [deleteTarget, setDeleteTarget] = useState<UserWithRoles | null>(
        null,
    );

    const usersTsv = useMemo(() => buildUsersTsv(users.data), [users.data]);
    const hasUsersToCopy = users.data.length > 0;
    const copiedCurrentRows = copiedText === usersTsv;
    const selectedRole = roles.find((role) => String(role.id) === filters.role);
    const filteredRoles = roles.filter((role) => {
        if (!roleFilterSearch.trim()) {
            return true;
        }

        const query = roleFilterSearch.toLowerCase();

        return (
            (role.display_name?.toLowerCase().includes(query) ?? false) ||
            role.name.toLowerCase().includes(query)
        );
    });

    async function handleCopyForExcel() {
        if (!hasUsersToCopy) {
            return;
        }

        await copy(usersTsv);
    }

    function handleDeactivate(user: UserWithRoles) {
        setProcessing(true);
        router.patch(
            DeactivateUserController.url(user),
            {},
            {
                onFinish: () => {
                    setProcessing(false);
                    setDeactivateTarget(null);
                },
            },
        );
    }

    function handleActivate(user: UserWithRoles) {
        setProcessing(true);
        router.patch(
            ActivateUserController.url(user),
            {},
            {
                onFinish: () => {
                    setProcessing(false);
                    setActivateTarget(null);
                },
            },
        );
    }

    function handleDelete(user: UserWithRoles) {
        setProcessing(true);
        router.delete(destroy.url(user), {
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
                { search: search || undefined, ...params },
                { preserveState: true, replace: true },
            );
        },
        [search],
    );

    function handleSearchSubmit(e: React.FormEvent) {
        e.preventDefault();
        applyFilter({ search: search || undefined });
    }

    function handleRoleChange(value: string) {
        applyFilter({ role: value === 'all' ? undefined : value });
    }

    function handleStatusChange(value: string) {
        applyFilter({ status: value === 'all' ? undefined : value });
    }

    function toggleSelectAll() {
        if (selectedIds.length === users.data.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(users.data.map((u) => u.id));
        }
    }

    function toggleSelect(id: number) {
        setSelectedIds((prev) =>
            prev.includes(id)
                ? prev.filter((sid) => sid !== id)
                : [...prev, id],
        );
    }

    const allSelected =
        users.data.length > 0 && selectedIds.length === users.data.length;
    const someSelected = selectedIds.length > 0 && !allSelected;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usuarios" />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <PageHeader
                    icon={Users}
                    title="Usuarios"
                    description="Gestiona las cuentas de usuario, sus roles y permisos de acceso."
                    actions={
                        <>
                            <HelpLink category="users" slug="manage-users" />
                            {canExport && (
                                <>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={handleCopyForExcel}
                                        disabled={!hasUsersToCopy}
                                    >
                                        <Copy className="size-4" />
                                        {copiedCurrentRows
                                            ? 'Copiado para Excel'
                                            : 'Copiar para Excel'}
                                    </Button>

                                    <Button asChild variant="outline" size="sm">
                                        <a href={ExportUsersController.url()}>
                                            <Download className="size-4" />
                                            Exportar
                                        </a>
                                    </Button>
                                </>
                            )}
                            {canCreate && (
                                <Button asChild size="sm">
                                    <Link href={create.url()}>
                                        <PlusCircle className="size-4" />
                                        Crear usuario
                                    </Link>
                                </Button>
                            )}
                            <CopilotSheet
                                trigger={
                                    <Button
                                        data-testid="copilot-open"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                    >
                                        <Sparkles className="size-4" />
                                        Copiloto
                                    </Button>
                                }
                            />
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
                                placeholder="Buscar por nombre o email…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="pl-9"
                            />
                        </form>

                        <div className="flex flex-wrap items-center gap-2">
                            <DropdownMenu
                                open={roleDropdownOpen}
                                onOpenChange={(open) => {
                                    setRoleDropdownOpen(open);

                                    if (!open) {
                                        setRoleFilterSearch('');
                                    }
                                }}
                            >
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="w-40 justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {filters.role === 'none'
                                                ? 'Sin roles'
                                                : filters.role
                                                  ? (selectedRole?.display_name ??
                                                    selectedRole?.name ??
                                                    'Rol')
                                                  : 'Todos los roles'}
                                        </span>
                                        <ChevronsUpDown className="ml-1 size-3.5 shrink-0 opacity-50" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="start"
                                    className="w-56"
                                >
                                    <div className="px-2 py-1.5">
                                        <div className="relative">
                                            <Search className="absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                                            <input
                                                type="text"
                                                placeholder="Buscar rol…"
                                                value={roleFilterSearch}
                                                onChange={(e) =>
                                                    setRoleFilterSearch(
                                                        e.target.value,
                                                    )
                                                }
                                                className="h-8 w-full rounded-md border border-input bg-transparent pr-2 pl-7 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring"
                                                onKeyDown={(e) =>
                                                    e.stopPropagation()
                                                }
                                            />
                                        </div>
                                    </div>
                                    <DropdownMenuSeparator />
                                    <div className="max-h-48 overflow-y-auto">
                                        <DropdownMenuItem
                                            onSelect={() => {
                                                handleRoleChange('all');
                                                setRoleDropdownOpen(false);
                                            }}
                                        >
                                            {!filters.role && (
                                                <Check className="mr-2 size-3.5" />
                                            )}
                                            <span
                                                className={
                                                    !filters.role
                                                        ? ''
                                                        : 'ml-5.5'
                                                }
                                            >
                                                Todos los roles
                                            </span>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() => {
                                                handleRoleChange('none');
                                                setRoleDropdownOpen(false);
                                            }}
                                        >
                                            {filters.role === 'none' && (
                                                <Check className="mr-2 size-3.5" />
                                            )}
                                            <span
                                                className={
                                                    filters.role === 'none'
                                                        ? ''
                                                        : 'ml-5.5'
                                                }
                                            >
                                                Sin roles asignados
                                            </span>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        {filteredRoles.map((role) => (
                                            <DropdownMenuItem
                                                key={role.id}
                                                onSelect={() => {
                                                    handleRoleChange(
                                                        String(role.id),
                                                    );
                                                    setRoleDropdownOpen(false);
                                                }}
                                            >
                                                {filters.role ===
                                                    String(role.id) && (
                                                    <Check className="mr-2 size-3.5" />
                                                )}
                                                <span
                                                    className={
                                                        filters.role ===
                                                        String(role.id)
                                                            ? ''
                                                            : 'ml-5.5'
                                                    }
                                                >
                                                    {role.display_name ??
                                                        role.name}
                                                </span>
                                            </DropdownMenuItem>
                                        ))}
                                    </div>
                                </DropdownMenuContent>
                            </DropdownMenu>

                            <Select
                                defaultValue={filters.status ?? 'all'}
                                onValueChange={handleStatusChange}
                            >
                                <SelectTrigger className="w-36">
                                    <SelectValue placeholder="Estado" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos</SelectItem>
                                    <SelectItem value="active">
                                        Activos
                                    </SelectItem>
                                    <SelectItem value="inactive">
                                        Inactivos
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </Card>

                {selectedIds.length > 0 && (
                    <BulkActionBar
                        selectedIds={selectedIds}
                        onClear={() => setSelectedIds([])}
                    />
                )}

                {users.data.length === 0 ? (
                    <Card className="py-0">
                        <EmptyState
                            icon={Users}
                            title="Sin usuarios"
                            description="No hay usuarios que coincidan con los filtros aplicados."
                            action={
                                canCreate ? (
                                    <Button asChild size="sm">
                                        <Link href={create.url()}>
                                            <PlusCircle className="size-4" />
                                            Crear usuario
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
                                        <TableHead className="w-10">
                                            <Checkbox
                                                checked={
                                                    allSelected
                                                        ? true
                                                        : someSelected
                                                          ? 'indeterminate'
                                                          : false
                                                }
                                                onCheckedChange={
                                                    toggleSelectAll
                                                }
                                                aria-label="Seleccionar todos"
                                            />
                                        </TableHead>
                                        <TableHead>Usuario</TableHead>
                                        <TableHead>Roles</TableHead>
                                        <TableHead>Estado</TableHead>
                                        <TableHead className="w-12" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {users.data.map((user) => (
                                        <TableRow
                                            key={user.id}
                                            className="group transition-colors"
                                            data-state={
                                                selectedIds.includes(user.id)
                                                    ? 'selected'
                                                    : undefined
                                            }
                                        >
                                            <TableCell>
                                                <Checkbox
                                                    checked={selectedIds.includes(
                                                        user.id,
                                                    )}
                                                    onCheckedChange={() =>
                                                        toggleSelect(user.id)
                                                    }
                                                    aria-label={`Seleccionar ${user.name}`}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-3">
                                                    <UserAvatar
                                                        name={user.name}
                                                        size="sm"
                                                    />
                                                    <div className="flex flex-col gap-0.5">
                                                        <Link
                                                            href={show.url(
                                                                user,
                                                            )}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {user.name}
                                                        </Link>
                                                        <span className="text-xs text-muted-foreground">
                                                            {user.email}
                                                        </span>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {user.roles.map((role) => (
                                                        <Badge
                                                            key={role.id}
                                                            variant="secondary"
                                                            className="font-normal"
                                                        >
                                                            {role.display_name ??
                                                                role.name}
                                                        </Badge>
                                                    ))}
                                                    {user.roles.length ===
                                                        0 && (
                                                        <span className="text-xs text-muted-foreground italic">
                                                            Sin roles
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    active={user.is_active}
                                                />
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
                                                                    user,
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
                                                                        user,
                                                                    )}
                                                                >
                                                                    <Pencil className="mr-2 size-4" />
                                                                    Editar
                                                                </Link>
                                                            </DropdownMenuItem>
                                                        )}
                                                        {canSendReset && (
                                                            <DropdownMenuItem
                                                                onSelect={() =>
                                                                    router.post(
                                                                        SendPasswordResetController.url(
                                                                            user,
                                                                        ),
                                                                        {},
                                                                    )
                                                                }
                                                            >
                                                                <KeyRound className="mr-2 size-4" />
                                                                Enviar reset
                                                            </DropdownMenuItem>
                                                        )}
                                                        {canDeactivate && (
                                                            <>
                                                                <DropdownMenuSeparator />
                                                                {user.is_active ? (
                                                                    <DropdownMenuItem
                                                                        onSelect={() =>
                                                                            setDeactivateTarget(
                                                                                user,
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
                                                                                user,
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
                                                                            user,
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
                            links={users.links}
                            from={users.from}
                            to={users.to}
                            total={users.total}
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
                title="¿Desactivar usuario?"
                description={`"${deactivateTarget?.name}" perderá el acceso inmediatamente y sus sesiones activas serán cerradas.`}
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
                title="¿Activar usuario?"
                description={`"${activateTarget?.name}" podrá iniciar sesión nuevamente.`}
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
                title="¿Eliminar usuario?"
                description={`Esta acción es permanente. El usuario "${deleteTarget?.name}" y todos sus datos serán eliminados.`}
                confirmLabel="Eliminar permanentemente"
                variant="destructive"
                onConfirm={() => deleteTarget && handleDelete(deleteTarget)}
                loading={processing}
            />
        </AppLayout>
    );
}
