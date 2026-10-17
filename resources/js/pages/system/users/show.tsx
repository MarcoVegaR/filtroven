import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    CheckCircle,
    ChevronDown,
    ChevronRight,
    Edit,
    Key,
    KeyRound,
    Shield,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

import {
    destroy,
    edit as editAction,
    index,
    show as showAction,
} from '@/actions/App/Http/Controllers/System/UserController';
import ActivateUserController from '@/actions/App/Http/Controllers/System/Users/ActivateUserController';
import DeactivateUserController from '@/actions/App/Http/Controllers/System/Users/DeactivateUserController';
import SendPasswordResetController from '@/actions/App/Http/Controllers/System/Users/SendPasswordResetController';
import { HelpLink } from '@/components/help/help-link';
import { CopilotSheet } from '@/components/system/copilot/copilot-sheet';
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
    GroupedEffectivePermissions,
    UserWithRoles,
} from '@/types';

type Props = {
    user: UserWithRoles;
    groupedEffectivePermissions: GroupedEffectivePermissions;
};

const ROLES_COLLAPSED_LIMIT = 6;
const PERMS_COLLAPSED_LIMIT = 8;

function RolesSection({ roles }: { roles: UserWithRoles['roles'] }) {
    const [expanded, setExpanded] = useState(false);
    const hasMore = roles.length > ROLES_COLLAPSED_LIMIT;
    const visible = expanded ? roles : roles.slice(0, ROLES_COLLAPSED_LIMIT);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm font-semibold tracking-wide uppercase">
                    Roles asignados
                </CardTitle>
            </CardHeader>
            <CardContent>
                {roles.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Sin roles asignados.
                    </p>
                ) : (
                    <div className="space-y-2">
                        <div className="flex flex-wrap gap-2">
                            {visible.map((role) => (
                                <Badge
                                    key={role.id}
                                    variant={
                                        role.is_active ? 'default' : 'secondary'
                                    }
                                    className="gap-1.5 px-3 py-1"
                                >
                                    {role.display_name ?? role.name}
                                    {!role.is_active && (
                                        <span className="text-[10px] opacity-60">
                                            (inactivo)
                                        </span>
                                    )}
                                </Badge>
                            ))}
                        </div>
                        {hasMore && (
                            <button
                                type="button"
                                className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                                onClick={() => setExpanded((prev) => !prev)}
                            >
                                {expanded ? (
                                    <>
                                        <ChevronRight className="size-3" />
                                        Mostrar menos
                                    </>
                                ) : (
                                    <>
                                        <ChevronDown className="size-3" />
                                        Ver{' '}
                                        {roles.length -
                                            ROLES_COLLAPSED_LIMIT}{' '}
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

function EffectivePermissionsSection({
    grouped,
}: {
    grouped: GroupedEffectivePermissions;
}) {
    const [expanded, setExpanded] = useState(false);
    const entries = Object.entries(grouped);
    const totalPerms = entries.reduce(
        (acc, [, perms]) => acc + perms.length,
        0,
    );
    const hasMore = totalPerms > PERMS_COLLAPSED_LIMIT;

    // If collapsed, show only the first N permissions across groups
    let remainingSlots = expanded ? Infinity : PERMS_COLLAPSED_LIMIT;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm font-semibold tracking-wide uppercase">
                    Permisos efectivos
                </CardTitle>
                <p className="text-xs text-muted-foreground">
                    Permisos activos derivados de roles activos únicamente.
                </p>
            </CardHeader>
            <CardContent>
                {entries.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Sin permisos efectivos. Asigna un rol activo para que el
                        usuario tenga acceso.
                    </p>
                ) : (
                    <div className="space-y-4">
                        {entries.map(([group, perms], idx) => {
                            if (remainingSlots <= 0) {
                                return null;
                            }

                            const visiblePerms = perms.slice(0, remainingSlots);
                            remainingSlots -= visiblePerms.length;

                            return (
                                <div key={group}>
                                    {idx > 0 && <Separator className="mb-4" />}
                                    <p className="mb-3 text-sm font-medium text-foreground">
                                        {groupLabel(group)}
                                    </p>
                                    <div className="space-y-2">
                                        {visiblePerms.map((p) => (
                                            <div
                                                key={p.id}
                                                className="flex items-center justify-between gap-3 rounded-lg bg-muted/40 px-3 py-2"
                                            >
                                                <span className="text-sm">
                                                    {p.display_name ?? p.name}
                                                </span>
                                                <div className="flex flex-wrap justify-end gap-1">
                                                    {Object.entries(
                                                        p.roles,
                                                    ).map(
                                                        ([
                                                            roleName,
                                                            roleDisplay,
                                                        ]) => (
                                                            <Badge
                                                                key={roleName}
                                                                variant="outline"
                                                                className="text-xs"
                                                            >
                                                                {roleDisplay ||
                                                                    roleName}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                        {hasMore && (
                            <button
                                type="button"
                                className="flex w-full items-center justify-center gap-1.5 rounded-lg py-2 text-sm text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground"
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
                                        Ver todos los permisos ({totalPerms})
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

export default function UserShow({ user, groupedEffectivePermissions }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Usuarios', href: index.url() },
        { title: user.name, href: showAction.url(user) },
    ];

    const canUpdate = useCan('system.users.update');
    const canDeactivate = useCan('system.users.deactivate');
    const canDelete = useCan('system.users.delete');
    const canSendReset = useCan('system.users.send-reset');

    const [showDeactivateDialog, setShowDeactivateDialog] = useState(false);
    const [showActivateDialog, setShowActivateDialog] = useState(false);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [processing, setProcessing] = useState(false);

    function handleAction(
        url: string,
        method: 'patch' | 'delete',
        afterDialog: () => void,
    ) {
        setProcessing(true);

        if (method === 'delete') {
            router.delete(url, {
                onFinish: () => {
                    setProcessing(false);
                    afterDialog();
                },
            });
        } else {
            router.patch(
                url,
                {},
                {
                    onFinish: () => {
                        setProcessing(false);
                        afterDialog();
                    },
                },
            );
        }
    }

    const totalPermissions = Object.values(groupedEffectivePermissions).reduce(
        (acc, perms) => acc + perms.length,
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={user.name} />

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

                {/* Profile header card */}
                <Card className="gap-0 py-0">
                    <div className="flex flex-col gap-6 p-6 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-4">
                            <UserAvatar name={user.name} size="lg" />
                            <div className="space-y-1">
                                <div className="flex items-center gap-3">
                                    <h1 className="text-2xl font-semibold tracking-tight">
                                        {user.name}
                                    </h1>
                                    <StatusBadge active={user.is_active} />
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {user.email}
                                </p>
                            </div>
                        </div>

                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                            {canUpdate && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={editAction.url(user)}>
                                        <Edit className="size-4" />
                                        Editar
                                    </Link>
                                </Button>
                            )}

                            <HelpLink category="users" slug="user-lifecycle" />

                            <CopilotSheet
                                subjectUser={{
                                    id: user.id,
                                    name: user.name,
                                    email: user.email,
                                }}
                                trigger={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                    >
                                        <Sparkles className="size-4" />
                                        Copiloto
                                    </Button>
                                }
                            />

                            {canSendReset && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            SendPasswordResetController.url(
                                                user,
                                            ),
                                            {},
                                        )
                                    }
                                >
                                    <KeyRound className="size-4" />
                                    Enviar reset
                                </Button>
                            )}

                            {canDeactivate && user.is_active && (
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

                            {canDeactivate && !user.is_active && (
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

                {!user.is_active && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                        Esta cuenta está desactivada. El usuario no puede
                        iniciar sesión.
                    </div>
                )}

                {/* Stats */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <StatCard
                        icon={Shield}
                        label="Roles"
                        value={user.roles.length}
                        accent="primary"
                    />
                    <StatCard
                        icon={Key}
                        label="Permisos efectivos"
                        value={totalPermissions}
                        accent="success"
                    />
                </div>

                {/* Roles */}
                <RolesSection roles={user.roles} />

                {/* Effective permissions grouped */}
                <EffectivePermissionsSection
                    grouped={groupedEffectivePermissions}
                />
            </div>

            <ConfirmationDialog
                open={showDeactivateDialog}
                onOpenChange={setShowDeactivateDialog}
                title="¿Desactivar usuario?"
                description={`"${user.name}" perderá el acceso inmediatamente y sus sesiones activas serán cerradas.`}
                confirmLabel="Desactivar"
                variant="destructive"
                onConfirm={() =>
                    handleAction(
                        DeactivateUserController.url(user),
                        'patch',
                        () => setShowDeactivateDialog(false),
                    )
                }
                loading={processing}
            />

            <ConfirmationDialog
                open={showActivateDialog}
                onOpenChange={setShowActivateDialog}
                title="¿Activar usuario?"
                description={`"${user.name}" podrá iniciar sesión nuevamente.`}
                confirmLabel="Activar"
                variant="default"
                onConfirm={() =>
                    handleAction(
                        ActivateUserController.url(user),
                        'patch',
                        () => setShowActivateDialog(false),
                    )
                }
                loading={processing}
            />

            <ConfirmationDialog
                open={showDeleteDialog}
                onOpenChange={setShowDeleteDialog}
                title="¿Eliminar usuario?"
                description={`Esta acción es permanente. El usuario "${user.name}" y todos sus datos serán eliminados.`}
                confirmLabel="Eliminar permanentemente"
                variant="destructive"
                onConfirm={() =>
                    handleAction(destroy.url(user), 'delete', () =>
                        setShowDeleteDialog(false),
                    )
                }
                loading={processing}
            />
        </AppLayout>
    );
}
