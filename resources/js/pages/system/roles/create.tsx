import { Form, Head, router } from '@inertiajs/react';
import { ShieldPlus } from 'lucide-react';

import { useState } from 'react';
import {
    create as createAction,
    index,
    store,
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, GroupedPermissions } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Roles', href: index.url() },
    { title: 'Crear', href: createAction.url() },
];

type Props = {
    groupedPermissions: GroupedPermissions;
};

export default function RoleCreate({ groupedPermissions }: Props) {
    const [selectedIds, setSelectedIds] = useState<number[]>([]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Crear rol" />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <div className="mx-auto max-w-2xl space-y-6">
                    <PageHeader
                        icon={ShieldPlus}
                        title="Crear rol"
                        description="Define un nuevo rol y asigna los permisos correspondientes."
                        actions={
                            <HelpLink
                                category="roles-and-permissions"
                                slug="create-role"
                            />
                        }
                    />
                    <Form {...store.form()} className="space-y-6">
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
                                            Selecciona los permisos que tendrá
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

                                        <PermissionPicker
                                            groupedPermissions={
                                                groupedPermissions
                                            }
                                            selectedIds={selectedIds}
                                            onChange={setSelectedIds}
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
                                            : 'Crear rol'}
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
