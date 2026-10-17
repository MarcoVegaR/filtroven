import { Form, Head, router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';

import {
    edit as editAction,
    index,
    update,
} from '@/actions/App/Http/Controllers/System/RoleController';
import { HelpLink } from '@/components/help/help-link';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/system/page-header';
import { PermissionPicker } from '@/components/system/permission-picker';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, GroupedPermissions, RoleData } from '@/types';

type Props = {
    role: RoleData;
    groupedPermissions: GroupedPermissions;
    selectedPermissionIds: number[];
};

export default function RoleEdit({
    role,
    groupedPermissions,
    selectedPermissionIds,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Roles', href: index.url() },
        { title: role.display_name ?? role.name, href: editAction.url(role) },
    ];

    const [selectedIds, setSelectedIds] = useState<number[]>(
        selectedPermissionIds,
    );
    const [confirmedIds, setConfirmedIds] = useState<number[]>(
        selectedPermissionIds,
    );
    const [showConfirm, setShowConfirm] = useState(false);
    const [pendingIds, setPendingIds] = useState<number[] | null>(null);

    function handlePermissionChange(ids: number[]) {
        const removed = confirmedIds.filter((id) => !ids.includes(id));

        if (removed.length > 0) {
            setPendingIds(ids);
            setShowConfirm(true);
        } else {
            setSelectedIds(ids);
            setConfirmedIds(ids);
        }
    }

    function confirmPermissionChange() {
        if (pendingIds !== null) {
            setSelectedIds(pendingIds);
            setConfirmedIds(pendingIds);
        }

        setPendingIds(null);
        setShowConfirm(false);
    }

    function cancelPermissionChange() {
        setPendingIds(null);
        setShowConfirm(false);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Editar ${role.display_name ?? role.name}`} />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <div className="mx-auto max-w-2xl space-y-6">
                    <PageHeader
                        icon={Pencil}
                        title={`Editar rol: ${role.display_name ?? role.name}`}
                        description="Modifica los datos del rol y sus permisos asignados."
                        actions={
                            <HelpLink
                                category="roles-and-permissions"
                                slug="assign-permissions"
                            />
                        }
                    />
                    <Form {...update.form(role)} className="space-y-6">
                        {({ errors, processing }) => (
                            <>
                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            Información del rol
                                        </CardTitle>
                                        <CardDescription>
                                            Identificación y descripción del
                                            rol.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">
                                                Identificador{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                defaultValue={role.name}
                                                placeholder="ej. editor-contenido"
                                                autoComplete="off"
                                                required
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Nombre técnico en minúsculas,
                                                sin espacios.
                                            </p>
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="display_name">
                                                Nombre para mostrar
                                            </Label>
                                            <Input
                                                id="display_name"
                                                name="display_name"
                                                defaultValue={
                                                    role.display_name ?? ''
                                                }
                                                placeholder="ej. Editor de contenido"
                                            />
                                            <InputError
                                                message={errors.display_name}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="description">
                                                Descripción
                                            </Label>
                                            <Textarea
                                                id="description"
                                                name="description"
                                                defaultValue={
                                                    role.description ?? ''
                                                }
                                                placeholder="Describe brevemente el propósito de este rol…"
                                                rows={3}
                                            />
                                            <InputError
                                                message={errors.description}
                                            />
                                        </div>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>Permisos</CardTitle>
                                        <CardDescription>
                                            Gestiona los permisos asignados a
                                            este rol.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {/* Hidden inputs for selected permissions */}
                                        {selectedIds.map((id) => (
                                            <input
                                                key={id}
                                                type="hidden"
                                                name="permissions[]"
                                                value={id}
                                            />
                                        ))}
                                        {selectedIds.length === 0 && (
                                            <input
                                                type="hidden"
                                                name="permissions"
                                                value=""
                                            />
                                        )}

                                        <PermissionPicker
                                            groupedPermissions={
                                                groupedPermissions
                                            }
                                            selectedIds={selectedIds}
                                            onChange={handlePermissionChange}
                                        />
                                        <InputError
                                            message={errors.permissions}
                                        />
                                    </CardContent>
                                </Card>

                                <div className="flex items-center gap-3">
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Guardando…'
                                            : 'Guardar cambios'}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            router.visit(index.url())
                                        }
                                        disabled={processing}
                                    >
                                        Cancelar
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>

            <ConfirmationDialog
                open={showConfirm}
                onOpenChange={(open) => {
                    if (!open) {
                        cancelPermissionChange();
                    }
                }}
                title="¿Quitar permisos?"
                description="Estás a punto de quitar uno o más permisos de este rol. Los usuarios que tengan este rol como única fuente de esos permisos perderán el acceso inmediatamente."
                confirmLabel="Sí, quitar permisos"
                cancelLabel="Cancelar"
                variant="destructive"
                onConfirm={confirmPermissionChange}
            />
        </AppLayout>
    );
}
