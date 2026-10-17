import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    CheckCircle,
    ChevronDown,
    ChevronRight,
    Edit,
    Key,
    Trash2,
    Users,
} from 'lucide-react';
import { useState } from 'react';

import RoleActivateController from '@/actions/App/Http/Controllers/System/RoleActivateController';
import {
    destroy,
    edit as editAction,
    index,
    show as showAction,
} from '@/actions/App/Http/Controllers/System/RoleController';
import RoleDeactivateController from '@/actions/App/Http/Controllers/System/RoleDeactivateController';
import { HelpLink } from '@/components/help/help-link';
import { StatCard } from '@/components/system/stat-card';
import { StatusBadge } from '@/components/system/status-badge';
import { UserAvatar } from '@/components/system/user-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import { Separator } from '@/components/ui/separator';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { groupLabel } from '@/lib/system';
import type {
    BreadcrumbItem,
    GroupedPermissions,
    RoleData,
    UserWithRoles,
} from '@/types';

type RoleUser = Pick<UserWithRoles, 'id' | 'name' | 'email' | 'is_active'>;

type Props = {
    role: RoleData & { users_count: number; users: RoleUser[] };
    groupedPermissions: GroupedPermissions;
};

const USERS_COLLAPSED_LIMIT = 5;

function UsersSection({ users }: { users: RoleUser[] }) {
    const [expanded, setExpanded] = useState(false);
    const hasMore = users.length > USERS_COLLAPSED_LIMIT;
    const visible = expanded ? users : users.slice(0, USERS_COLLAPSED_LIMIT);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm font-semibold tracking-wide uppercase">
                    Usuarios con este rol
                </CardTitle>
            </CardHeader>
            <CardContent>
                {users.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Ningún usuario tiene asignado este rol.
                    </p>
                ) : (
                    <div className="space-y-1">
                        {visible.map((user) => (
                            <Link
                                key={user.id}
                                href={`/system/users/${user.id}`}
                                className="flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-muted/50"
                            >
                                <UserAvatar name={user.name} size="sm" />
                                <div className="flex flex-1 flex-col gap-0.5 overflow-hidden">
                                    <span className="truncate text-sm font-medium">
                                        {user.name}
                                    </span>
                                    <span className="truncate text-xs text-muted-foreground">
                                        {user.email}
                                    </span>
                                </div>
                                <StatusBadge active={user.is_active} />
                            </Link>
                        ))}
                        {hasMore && (
                            <button
                                type="button"
                                className="flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground"
                                onClick={() => setExpanded((prev) => !prev)}
                            >
                                {expanded ? (
                                    <>
                                        <ChevronRight className="size-3.5" />
                                        Mostrar menos
                                    </>
                                ) : (
                                    <>
                                        <ChevronDown className="size-3.5" />
                                        Ver{' '}
                                        {users.length -
                                            USERS_COLLAPSED_LIMIT}{' '}
                                        más
                                    </>
                                )}
                            </button>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function RoleShow({ role, groupedPermissions }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Roles', href: index.url() },
        { title: role.display_name ?? role.name, href: showAction.url(role) },
    ];

    const canUpdate = useCan('system.roles.update');
    const canDeactivate = useCan('system.roles.deactivate');
    const canDelete = useCan('system.roles.delete');

    const [showDeactivateDialog, setShowDeactivateDialog] = useState(false);
    const [showActivateDialog, setShowActivateDialog] = useState(false);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [processing, setProcessing] = useState(false);

    function handleDeactivate() {
        setProcessing(true);
        router.patch(
            RoleDeactivateController.url(role),
            {},
            {
                onFinish: () => {
                    setProcessing(false);
                    setShowDeactivateDialog(false);
                },
            },
        );
    }

    function handleActivate() {
        setProcessing(true);
        router.patch(
            RoleActivateController.url(role),
            {},
            {
                onFinish: () => {
                    setProcessing(false);
                    setShowActivateDialog(false);
                },
            },
        );
    }

    function handleDelete() {
        setProcessing(true);
        router.delete(destroy.url(role), {
            onFinish: () => {
                setProcessing(false);
                setShowDeleteDialog(false);
            },
        });
    }

    const permissionsCount =
        role.permissions_count ?? role.permissions?.length ?? 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={role.display_name ?? role.name} />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                {/* Back link */}
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

                {/* Header card */}
                <Card className="gap-0 py-0">
                    <div className="flex flex-col gap-6 p-6 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1">
                            <div className="flex items-center gap-3">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {role.display_name ?? role.name}
                                </h1>
                                <StatusBadge active={role.is_active} />
                            </div>
                            {role.display_name && (
                                <p className="font-mono text-sm text-muted-foreground">
                                    {role.name}
                                </p>
                            )}
                            {role.description && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {role.description}
                                </p>
                            )}
                        </div>

                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                            {canUpdate && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={editAction.url(role)}>
                                        <Edit className="size-4" />
                                        Editar
                                    </Link>
                                </Button>
                            )}

                            <HelpLink
                                category="roles-and-permissions"
                                slug="manage-roles"
                            />

                            {canDeactivate && role.is_active && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setShowDeactivateDialog(true)
                                    }
                                >
                                    <Ban className="size-4" />
                                    Desactivar
                                </Button>
                            )}

                            {canDeactivate && !role.is_active && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setShowActivateDialog(true)}
                                >
                                    <CheckCircle className="size-4" />
                                    Activar
                                </Button>
                            )}

                            {canDelete && (
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={() => setShowDeleteDialog(true)}
                                >
                                    <Trash2 className="size-4" />
                                    Eliminar
                                </Button>
                            )}
                        </div>
                    </div>
                </Card>

                {!role.is_active && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                        Este rol está inactivo. Los usuarios asignados a este
                        rol no tendrán los permisos asociados hasta que el rol
                        sea reactivado.
                    </div>
                )}

                {/* Stats */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <StatCard
                        icon={Key}
                        label="Permisos"
                        value={permissionsCount}
                        accent="primary"
                    />
                    <StatCard
                        icon={Users}
                        label="Usuarios"
                        value={role.users_count ?? 0}
                        accent="success"
                    />
                </div>

                {/* Users with this role */}
                <UsersSection users={role.users} />

                {/* Permissions grouped */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold tracking-wide uppercase">
                            Permisos asignados
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {Object.keys(groupedPermissions).length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Este rol no tiene permisos asignados.
                            </p>
                        ) : (
                            <div className="space-y-4">
                                {Object.entries(groupedPermissions).map(
                                    ([group, perms], idx) => (
                                        <div key={group}>
                                            {idx > 0 && (
                                                <Separator className="mb-4" />
                                            )}
                                            <p className="mb-3 text-sm font-medium text-foreground">
                                                {groupLabel(group)}
                                            </p>
                                            <div className="flex flex-wrap gap-2">
                                                {perms.map((p) => (
                                                    <Badge
                                                        key={p.id}
                                                        variant="secondary"
                                                        className="px-3 py-1"
                                                    >
                                                        {p.display_name ??
                                                            p.name}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </div>
                                    ),
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <ConfirmationDialog
                open={showDeactivateDialog}
                onOpenChange={setShowDeactivateDialog}
                title="¿Desactivar rol?"
                description={`Los ${role.users_count ?? 0} usuario(s) asignados a "${role.display_name ?? role.name}" perderán los permisos de este rol inmediatamente.`}
                confirmLabel="Desactivar"
                variant="destructive"
                onConfirm={handleDeactivate}
                loading={processing}
            />

            <ConfirmationDialog
                open={showActivateDialog}
                onOpenChange={setShowActivateDialog}
                title="¿Activar rol?"
                description={`Los usuarios asignados a "${role.display_name ?? role.name}" recuperarán los permisos de este rol.`}
                confirmLabel="Activar"
                variant="default"
                onConfirm={handleActivate}
                loading={processing}
            />

            <ConfirmationDialog
                open={showDeleteDialog}
                onOpenChange={setShowDeleteDialog}
                title="¿Eliminar rol?"
                description={`Esta acción es permanente. El rol "${role.display_name ?? role.name}" será eliminado del sistema.`}
                confirmLabel="Eliminar permanentemente"
                variant="destructive"
                onConfirm={handleDelete}
                loading={processing}
            />
        </AppLayout>
    );
}
