import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';

import BulkDeactivateUsersController from '@/actions/App/Http/Controllers/System/Users/BulkDeactivateUsersController';
import { Button } from '@/components/ui/button';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import { useCan } from '@/hooks/use-can';
import { cn } from '@/lib/utils';
import type { BulkActionPayload } from '@/types/system.d';

type BulkActionBarProps = {
    selectedIds: number[];
    onClear: () => void;
    className?: string;
};

type PendingAction = 'deactivate' | 'activate' | 'delete' | null;

const actionLabels: Record<
    NonNullable<PendingAction>,
    { title: string; description: string; confirm: string }
> = {
    deactivate: {
        title: '¿Desactivar usuarios?',
        description:
            'Los usuarios seleccionados perderán acceso inmediatamente y sus sesiones serán cerradas.',
        confirm: 'Desactivar',
    },
    activate: {
        title: '¿Activar usuarios?',
        description:
            'Los usuarios seleccionados podrán iniciar sesión nuevamente.',
        confirm: 'Activar',
    },
    delete: {
        title: '¿Eliminar usuarios?',
        description:
            'Esta acción es permanente. Los usuarios eliminados no se pueden recuperar.',
        confirm: 'Eliminar',
    },
};

/**
 * BulkActionBar — shown when table rows are selected.
 * Dispatches bulk actions via BulkDeactivateUsersController endpoint.
 */
function BulkActionBar({
    selectedIds,
    onClear,
    className,
}: BulkActionBarProps) {
    const canDeactivate = useCan('system.users.deactivate');
    const canDelete = useCan('system.users.delete');

    const [pendingAction, setPendingAction] = useState<PendingAction>(null);
    const [processing, setProcessing] = useState(false);

    if (selectedIds.length === 0) {
        return null;
    }

    function handleBulkAction(action: NonNullable<PendingAction>) {
        setPendingAction(action);
    }

    function confirmAction() {
        if (!pendingAction) {
            return;
        }

        const payload: BulkActionPayload = {
            action: pendingAction,
            ids: selectedIds,
        };
        setProcessing(true);

        router.post(
            BulkDeactivateUsersController.url(),
            payload as { action: string; ids: number[] },
            {
                onFinish: () => {
                    setProcessing(false);
                    setPendingAction(null);
                    onClear();
                },
            },
        );
    }

    const dialogProps = pendingAction ? actionLabels[pendingAction] : null;

    return (
        <>
            <div
                data-slot="bulk-action-bar"
                className={cn(
                    'flex items-center gap-3 rounded-md border border-border bg-background px-4 py-3 shadow-sm',
                    className,
                )}
            >
                <span className="text-sm font-medium">
                    {selectedIds.length} seleccionado
                    {selectedIds.length !== 1 ? 's' : ''}
                </span>

                <div className="flex flex-1 items-center gap-2">
                    {canDeactivate && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleBulkAction('deactivate')}
                        >
                            Desactivar
                        </Button>
                    )}

                    {canDeactivate && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleBulkAction('activate')}
                        >
                            Activar
                        </Button>
                    )}

                    {canDelete && (
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={() => handleBulkAction('delete')}
                        >
                            Eliminar
                        </Button>
                    )}
                </div>

                <Button
                    variant="ghost"
                    size="icon"
                    onClick={onClear}
                    aria-label="Limpiar selección"
                >
                    <X />
                </Button>
            </div>

            {dialogProps && (
                <ConfirmationDialog
                    open={pendingAction !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setPendingAction(null);
                        }
                    }}
                    title={dialogProps.title}
                    description={dialogProps.description}
                    confirmLabel={dialogProps.confirm}
                    variant={
                        pendingAction === 'delete' ? 'destructive' : 'default'
                    }
                    onConfirm={confirmAction}
                    loading={processing}
                />
            )}
        </>
    );
}

export { BulkActionBar };
export type { BulkActionBarProps };
