import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ShieldCheck, Users } from 'lucide-react';

import { index as permissionsIndex } from '@/actions/App/Http/Controllers/System/PermissionController';
import { show as roleShow } from '@/actions/App/Http/Controllers/System/RoleController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, PermissionData, RoleData } from '@/types';

type PermissionShowData = PermissionData & {
    roles: RoleData[];
};

type Props = {
    permission: PermissionShowData;
};

export default function PermissionShow({ permission }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Permisos', href: permissionsIndex.url() },
        {
            title: permission.display_name ?? permission.name,
            href: '#',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={permission.display_name ?? permission.name} />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <div>
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="-ml-2 gap-1.5"
                    >
                        <Link href={permissionsIndex.url()}>
                            <ArrowLeft className="size-4" />
                            Volver
                        </Link>
                    </Button>
                </div>

                <Card className="gap-0 py-0">
                    <div className="space-y-2 p-6">
                        <div className="flex items-center gap-2">
                            <ShieldCheck className="size-5 text-muted-foreground" />
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {permission.display_name ?? permission.name}
                            </h1>
                        </div>

                        <p className="font-mono text-sm text-muted-foreground">
                            {permission.name}
                        </p>

                        {permission.description && (
                            <p className="text-sm text-muted-foreground">
                                {permission.description}
                            </p>
                        )}
                    </div>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-sm font-semibold tracking-wide uppercase">
                            <Users className="size-4" />
                            Roles que usan este permiso
                            <Badge variant="outline" className="tabular-nums">
                                {permission.roles.length}
                            </Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {permission.roles.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No hay roles asignados a este permiso.
                            </p>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {permission.roles.map((role) => (
                                    <Badge
                                        key={role.id}
                                        variant="secondary"
                                        className="gap-1"
                                    >
                                        <Link
                                            href={roleShow.url(role)}
                                            className="hover:underline"
                                        >
                                            {role.display_name ?? role.name}
                                        </Link>
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
