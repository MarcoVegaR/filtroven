import { Form, Head, router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';

import {
    edit as editAction,
    index,
    update,
} from '@/actions/App/Http/Controllers/System/UserController';
import DeactivateUserController from '@/actions/App/Http/Controllers/System/Users/DeactivateUserController';
import { HelpLink } from '@/components/help/help-link';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/system/page-header';
import { RoleSelector } from '@/components/system/role-selector';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, RoleData, UserWithRoles } from '@/types';

type Props = {
    user: UserWithRoles;
    roles: RoleData[];
};

export default function UserEdit({ user, roles }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Usuarios', href: index.url() },
        { title: user.name, href: editAction.url(user) },
    ];

    const [selectedRoleIds, setSelectedRoleIds] = useState<number[]>(
        user.roles.map((r) => r.id),
    );

    const [showDeactivateDialog, setShowDeactivateDialog] = useState(false);
    const [deactivating, setDeactivating] = useState(false);

    function handleDeactivate() {
        setDeactivating(true);
        router.patch(
            DeactivateUserController.url(user),
            {},
            {
                onFinish: () => {
                    setDeactivating(false);
                    setShowDeactivateDialog(false);
                },
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Editar ${user.name}`} />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <div className="mx-auto max-w-2xl space-y-6">
                    <PageHeader
                        icon={Pencil}
                        title={`Editar usuario: ${user.name}`}
                        description="Modifica los datos del usuario. Para cambiar la contraseña, usa la opción de enviar correo de restablecimiento."
                        actions={
                            <HelpLink category="users" slug="assign-roles" />
                        }
                    />
                    <Form {...update.form(user)} className="space-y-6">
                        {({ errors, processing }) => (
                            <>
                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            Información básica
                                        </CardTitle>
                                        <CardDescription>
                                            Datos de identificación del usuario.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">
                                                Nombre{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                defaultValue={user.name}
                                                placeholder="Nombre completo"
                                                autoComplete="name"
                                                required
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="email">
                                                Correo electrónico{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                name="email"
                                                defaultValue={user.email}
                                                placeholder="nombre@empresa.com"
                                                autoComplete="email"
                                                required
                                            />
                                            <InputError
                                                message={errors.email}
                                            />
                                        </div>

                                        <div className="flex items-center gap-3 rounded-lg border p-3">
                                            <Checkbox
                                                id="is_active"
                                                name="is_active"
                                                value="1"
                                                defaultChecked={user.is_active}
                                                onCheckedChange={(checked) => {
                                                    if (
                                                        !checked &&
                                                        user.is_active
                                                    ) {
                                                        setShowDeactivateDialog(
                                                            true,
                                                        );
                                                    }
                                                }}
                                            />
                                            <div className="flex flex-col gap-0.5">
                                                <Label htmlFor="is_active">
                                                    Cuenta activa
                                                </Label>
                                                {!user.is_active && (
                                                    <p className="text-xs text-muted-foreground">
                                                        Este usuario está
                                                        desactivado y no puede
                                                        iniciar sesión.
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                        <InputError
                                            message={errors.is_active}
                                        />
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>Roles</CardTitle>
                                        <CardDescription>
                                            Selecciona los roles que
                                            determinarán los permisos del
                                            usuario.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {/* Hidden inputs for selected roles */}
                                        {selectedRoleIds.map((id) => (
                                            <input
                                                key={id}
                                                type="hidden"
                                                name="roles[]"
                                                value={id}
                                            />
                                        ))}
                                        {selectedRoleIds.length === 0 && (
                                            <input
                                                type="hidden"
                                                name="roles"
                                                value=""
                                            />
                                        )}

                                        <RoleSelector
                                            roles={roles}
                                            selectedIds={selectedRoleIds}
                                            onChange={setSelectedRoleIds}
                                        />
                                        <InputError message={errors.roles} />
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
                open={showDeactivateDialog}
                onOpenChange={setShowDeactivateDialog}
                title="¿Desactivar usuario?"
                description={`"${user.name}" perderá el acceso inmediatamente y sus sesiones activas serán cerradas.`}
                confirmLabel="Desactivar"
                variant="destructive"
                onConfirm={handleDeactivate}
                loading={deactivating}
            />
        </AppLayout>
    );
}
