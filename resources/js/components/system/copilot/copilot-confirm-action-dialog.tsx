import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import type { CopilotActionProposal } from '@/lib/copilot';

type CopilotConfirmActionDialogProps = {
    action: CopilotActionProposal | null;
    open: boolean;
    loading: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
};

function dialogCopy(action: CopilotActionProposal): {
    title: string;
    description: string;
    confirmLabel: string;
    variant: 'default' | 'destructive';
} {
    if (action.action_type === 'activate') {
        return {
            title: 'Confirmar activación',
            description: action.summary,
            confirmLabel: 'Activar usuario',
            variant: 'default',
        };
    }

    if (action.action_type === 'send_reset') {
        return {
            title: 'Confirmar restablecimiento',
            description: action.summary,
            confirmLabel: 'Enviar correo',
            variant: 'default',
        };
    }

    if (action.action_type === 'create_user') {
        return {
            title: 'Confirmar alta guiada',
            description: action.summary,
            confirmLabel: 'Crear usuario',
            variant: 'default',
        };
    }

    return {
        title: 'Confirmar desactivación',
        description: action.summary,
        confirmLabel: 'Desactivar usuario',
        variant: 'destructive',
    };
}

export function CopilotConfirmActionDialog({
    action,
    open,
    loading,
    onOpenChange,
    onConfirm,
}: CopilotConfirmActionDialogProps) {
    if (!action) {
        return null;
    }

    const copy = dialogCopy(action);

    return (
        <ConfirmationDialog
            open={open}
            onOpenChange={onOpenChange}
            title={copy.title}
            description={copy.description}
            confirmLabel={copy.confirmLabel}
            confirmTestId="copilot-confirm-submit"
            variant={copy.variant}
            loading={loading}
            onConfirm={onConfirm}
        />
    );
}
