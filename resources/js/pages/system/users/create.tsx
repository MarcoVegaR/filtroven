import { Form, Head, router } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useRef, useState } from 'react';

import {
    create as createAction,
    index,
    store,
} from '@/actions/App/Http/Controllers/System/UserController';
import { HelpLink } from '@/components/help/help-link';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { PageHeader } from '@/components/system/page-header';
import { PasswordField } from '@/components/system/password-field';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, RoleData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Usuarios', href: index.url() },
    { title: 'Crear', href: createAction.url() },
];

type Props = {
    roles: RoleData[];
};

export default function UserCreate({ roles }: Props) {
    const [selectedRoleIds, setSelectedRoleIds] = useState<number[]>([]);
    const confirmationRef = useRef<HTMLInputElement>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Crear usuario" />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <div className="mx-auto max-w-2xl space-y-6">
                    <PageHeader
                        icon={UserPlus}
                        title="Crear usuario"
                        description="Crea una nueva cuenta de usuario y asigna los roles correspondientes."
                        actions={
                            <HelpLink category="users" slug="create-user" />
                        }
                    />
                    <Form {...store.form()} className="space-y-6">
                        {({ errors, processing }) => (
                            <>
                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            Información básica
                                        </CardTitle>
                                        <CardDescription>
                                            Datos de identificación y acceso del
                                            usuario.
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
                                                placeholder="nombre@empresa.com"
                                                autoComplete="email"
                                                required
                                            />
                                            <InputError
                                                message={errors.email}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password">
                                                Contraseña{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <PasswordField
                                                id="password"
                                                name="password"
                                                required
                                                confirmationRef={
                                                    confirmationRef
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Mín. 8 caracteres, mayúsculas,
                                                minúsculas, números y símbolos.
                                            </p>
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password_confirmation">
                                                Confirmar contraseña{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <PasswordInput
                                                ref={confirmationRef}
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                placeholder="Repite la contraseña"
                                                autoComplete="new-password"
                                            />
                                        </div>

                                        <div className="flex items-center gap-3 rounded-lg border p-3">
                                            <Checkbox
                                                id="is_active"
                                                name="is_active"
                                                value="1"
                                                defaultChecked
                                            />
                                            <Label htmlFor="is_active">
                                                Cuenta activa
                                            </Label>
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
                                            ? 'Creando…'
                                            : 'Crear usuario'}
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
        </AppLayout>
    );
}
