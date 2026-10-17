import {
    CheckCircle2,
    KeyRound,
    Power,
    PowerOff,
    ShieldAlert,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { CopilotActionProposal } from '@/lib/copilot';

type CopilotActionCardProps = {
    action: CopilotActionProposal;
    canConfirmAction: boolean;
    onConfirm: (action: CopilotActionProposal) => void;
};

function actionLabel(actionType: CopilotActionProposal['action_type']): string {
    return {
        activate: 'Activar usuario',
        deactivate: 'Desactivar usuario',
        send_reset: 'Enviar restablecimiento',
        create_user: 'Alta guiada',
    }[actionType];
}

function ActionIcon({
    actionType,
}: {
    actionType: CopilotActionProposal['action_type'];
}) {
    if (actionType === 'activate') {
        return <Power className="size-4" />;
    }

    if (actionType === 'deactivate') {
        return <PowerOff className="size-4" />;
    }

    if (actionType === 'send_reset') {
        return <KeyRound className="size-4" />;
    }

    return <ShieldAlert className="size-4" />;
}

export function CopilotActionCard({
    action,
    canConfirmAction,
    onConfirm,
}: CopilotActionCardProps) {
    return (
        <Card className="gap-3 border-primary/20 py-4">
            <CardContent className="space-y-3 px-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2 text-sm font-medium">
                            <ActionIcon actionType={action.action_type} />
                            {actionLabel(action.action_type)}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {action.summary}
                        </p>
                    </div>

                    <Badge
                        variant={action.can_execute ? 'default' : 'secondary'}
                    >
                        {action.can_execute
                            ? 'Lista para confirmar'
                            : 'Solo propuesta'}
                    </Badge>
                </div>

                {action.target.kind === 'user' && (
                    <div className="rounded-lg border bg-background px-3 py-2 text-sm">
                        <p className="font-medium">{action.target.name}</p>
                        <p className="text-muted-foreground">
                            {action.target.email}
                        </p>
                    </div>
                )}

                {action.target.kind === 'new_user' && (
                    <div className="rounded-lg border bg-background px-3 py-2 text-sm">
                        <p className="font-medium">
                            {action.target.name ?? 'Nuevo usuario'}
                        </p>
                        <p className="text-muted-foreground">
                            {action.target.email ?? 'Correo pendiente'}
                        </p>
                    </div>
                )}

                {Array.isArray(action.payload.role_labels) &&
                    action.payload.role_labels.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {action.payload.role_labels.map((roleLabel) =>
                                typeof roleLabel === 'string' ? (
                                    <Badge key={roleLabel} variant="secondary">
                                        {roleLabel}
                                    </Badge>
                                ) : null,
                            )}
                        </div>
                    )}

                {action.deny_reason && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        {action.deny_reason}
                    </div>
                )}

                <div className="flex justify-end">
                    <Button
                        data-test={`copilot-action-${action.action_type}`}
                        data-testid={`copilot-action-${action.action_type}`}
                        type="button"
                        size="sm"
                        onClick={() => onConfirm(action)}
                        disabled={!canConfirmAction}
                    >
                        <CheckCircle2 className="size-4" />
                        Confirmar acción
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
