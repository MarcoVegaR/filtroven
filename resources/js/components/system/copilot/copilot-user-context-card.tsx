import { CheckCircle2, Mail, ShieldCheck, ShieldX, User2 } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { CopilotUserContextCard as CopilotUserContextCardType } from '@/lib/copilot';

type CopilotUserContextCardProps = {
    card: CopilotUserContextCardType;
};

export function CopilotUserContextCard({ card }: CopilotUserContextCardProps) {
    if (!card.data.found || !card.data.user) {
        return (
            <Card className="gap-4 border-dashed bg-muted/30 py-4">
                <CardHeader className="px-4">
                    <CardTitle className="text-sm">
                        Usuario no encontrado
                    </CardTitle>
                </CardHeader>
                <CardContent className="px-4 text-sm text-muted-foreground">
                    No encontre un usuario valido para mostrar en esta
                    respuesta.
                </CardContent>
            </Card>
        );
    }

    const permissionCount = Object.values(
        card.data.effective_permissions ?? {},
    ).reduce((total, group) => total + group.length, 0);

    return (
        <Card className="gap-4 py-4">
            <CardHeader className="px-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <CardTitle className="text-sm">
                            {card.title ?? card.data.user.name}
                        </CardTitle>
                        <p className="text-sm text-muted-foreground">
                            {card.summary ?? card.data.user.email}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Badge
                            variant={
                                card.data.user.is_active
                                    ? 'default'
                                    : 'secondary'
                            }
                        >
                            {card.data.user.is_active ? 'Activo' : 'Inactivo'}
                        </Badge>
                        <Badge variant="outline">
                            {card.data.user.email_verified
                                ? 'Correo verificado'
                                : 'Correo pendiente'}
                        </Badge>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="grid gap-3 px-4 text-sm sm:grid-cols-2">
                <div className="rounded-lg border bg-muted/20 p-3">
                    <div className="mb-2 flex items-center gap-2 font-medium">
                        <User2 className="size-4" />
                        Estado de acceso
                    </div>
                    <div className="space-y-2 text-muted-foreground">
                        <p className="flex items-center gap-2">
                            {card.data.user.two_factor_enabled ? (
                                <CheckCircle2 className="size-4 text-emerald-600" />
                            ) : (
                                <ShieldX className="size-4 text-amber-600" />
                            )}
                            {card.data.user.two_factor_enabled
                                ? '2FA confirmado'
                                : '2FA no configurado'}
                        </p>
                        <p className="flex items-center gap-2">
                            <Mail className="size-4" />
                            {card.data.user.email}
                        </p>
                    </div>
                </div>

                <div className="rounded-lg border bg-muted/20 p-3">
                    <div className="mb-2 flex items-center gap-2 font-medium">
                        <ShieldCheck className="size-4" />
                        Acceso efectivo
                    </div>
                    <div className="space-y-2 text-muted-foreground">
                        <p>{card.data.roles?.length ?? 0} roles asignados</p>
                        <p>{permissionCount} permisos efectivos</p>
                        <div className="flex flex-wrap gap-2 pt-1">
                            {(card.data.roles ?? []).slice(0, 4).map((role) => (
                                <Badge key={role.id} variant="outline">
                                    {role.display_name ?? role.name}
                                </Badge>
                            ))}
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
