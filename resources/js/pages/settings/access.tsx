import { Head } from '@inertiajs/react';
import { Key, Shield } from 'lucide-react';

import AccessController from '@/actions/App/Http/Controllers/Settings/AccessController';
import { HelpLink } from '@/components/help/help-link';
import { PageHeader } from '@/components/system/page-header';
import { StatCard } from '@/components/system/stat-card';
import { StatusBadge } from '@/components/system/status-badge';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { groupLabel } from '@/lib/system';
import type {
    BreadcrumbItem,
    GroupedEffectivePermissions,
    RoleData,
} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Acceso', href: AccessController.show.url() },
];

type Props = {
    roles: RoleData[];
    effectivePermissions: GroupedEffectivePermissions;
};

export default function AccessSettings({ roles, effectivePermissions }: Props) {
    const totalPermissions = Object.values(effectivePermissions).reduce(
        (acc, perms) => acc + perms.length,
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Acceso" />

            <h1 className="sr-only">Acceso</h1>

            <SettingsLayout>
                <PageHeader
                    icon={Shield}
                    title="Acceso"
                    description="Revisa tus roles activos y los permisos efectivos que heredas dentro del sistema."
                    actions={
                        <HelpLink
                            category="security-access"
                            slug="review-my-access"
                        />
                    }
                />

                {/* Stats */}
                <div className="grid grid-cols-2 gap-4">
                    <StatCard
                        icon={Shield}
                        label="Roles"
                        value={roles.length}
                        accent="primary"
                    />
                    <StatCard
                        icon={Key}
                        label="Permisos"
                        value={totalPermissions}
                        accent="success"
                    />
                </div>

                {/* Roles section */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold tracking-wide uppercase">
                            Mis roles
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {roles.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No tienes ningún rol asignado.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {roles.map((role) => (
                                    <div
                                        key={role.id}
                                        className="flex items-start justify-between gap-4 rounded-lg bg-muted/40 px-4 py-3"
                                    >
                                        <div className="space-y-0.5">
                                            <p className="text-sm font-medium">
                                                {role.display_name ?? role.name}
                                            </p>
                                            {role.description && (
                                                <p className="text-xs text-muted-foreground">
                                                    {role.description}
                                                </p>
                                            )}
                                        </div>
                                        <StatusBadge active={role.is_active} />
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Effective permissions section */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold tracking-wide uppercase">
                            Permisos efectivos
                        </CardTitle>
                        <p className="text-xs text-muted-foreground">
                            Permisos activos derivados de tus roles activos
                        </p>
                    </CardHeader>
                    <CardContent>
                        {Object.keys(effectivePermissions).length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No tienes permisos efectivos. Contacta al
                                administrador si crees que esto es un error.
                            </p>
                        ) : (
                            <div className="space-y-4">
                                {Object.entries(effectivePermissions).map(
                                    ([group, perms], idx) => (
                                        <div key={group}>
                                            {idx > 0 && (
                                                <Separator className="mb-4" />
                                            )}
                                            <p className="mb-3 text-sm font-medium text-foreground">
                                                {groupLabel(group)}
                                            </p>
                                            <div className="space-y-2">
                                                {perms.map((p) => (
                                                    <div
                                                        key={p.id}
                                                        className="flex items-center justify-between gap-3 rounded-lg bg-muted/40 px-3 py-2"
                                                    >
                                                        <span className="text-sm">
                                                            {p.display_name ??
                                                                p.name}
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
                                                                        key={
                                                                            roleName
                                                                        }
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
                                    ),
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </SettingsLayout>
        </AppLayout>
    );
}
