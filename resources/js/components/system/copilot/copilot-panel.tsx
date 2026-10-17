import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { Skeleton } from '@/components/ui/skeleton';
import { useCan } from '@/hooks/use-can';
import { postCopilotAction, postCopilotMessage } from '@/lib/copilot';
import type {
    CopilotActionProposal,
    CopilotCapability,
    CopilotCredential,
} from '@/lib/copilot';

import { CopilotComposer } from './copilot-composer';
import { CopilotConfirmActionDialog } from './copilot-confirm-action-dialog';
import { CopilotCredentialCard } from './copilot-credential-card';
import { CopilotEmptyState } from './copilot-empty-state';
import { CopilotMessageList } from './copilot-message-list';
import type { CopilotTimelineItem } from './copilot-message-list';

type SubjectUser = {
    id: number;
    name: string;
    email: string;
};

type CopilotPanelProps = {
    subjectUser?: SubjectUser | null;
};

function timelineId(prefix: string): string {
    return `${prefix}-${Math.random().toString(36).slice(2, 10)}`;
}

export function CopilotPanel({ subjectUser }: CopilotPanelProps) {
    const { copilot } = usePage<{ copilot: CopilotCapability }>().props;
    const canViewUsers = useCan('system.users.view');
    const canAssignRoles = useCan('system.users.assign-role');
    const canExecuteCopilot = useCan('system.users-copilot.execute');
    const [draft, setDraft] = useState('');
    const [conversationId, setConversationId] = useState<string | undefined>();
    const [items, setItems] = useState<CopilotTimelineItem[]>([]);
    const [loading, setLoading] = useState(false);
    const [selectedAction, setSelectedAction] =
        useState<CopilotActionProposal | null>(null);
    const [actionPending, setActionPending] = useState(false);
    const [credential, setCredential] = useState<CopilotCredential | null>(
        null,
    );
    const endRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, [items, loading]);

    async function submitPrompt(promptOverride?: string) {
        const prompt = (promptOverride ?? draft).trim();

        if (!prompt || loading) {
            return;
        }

        setLoading(true);
        setDraft('');
        setItems((current) => [
            ...current,
            { id: timelineId('user'), role: 'user', content: prompt },
        ]);

        try {
            const envelope = await postCopilotMessage({
                prompt,
                conversation_id: conversationId,
                subject_user_id: subjectUser?.id,
            });

            setConversationId(envelope.conversation_id);
            setItems((current) => [
                ...current,
                {
                    id: timelineId('assistant'),
                    role: 'assistant',
                    response: envelope.response,
                },
            ]);
        } catch (error) {
            setItems((current) => [
                ...current,
                {
                    id: timelineId('error'),
                    role: 'error',
                    content:
                        error instanceof Error
                            ? error.message
                            : 'No pude obtener una respuesta del copiloto.',
                },
            ]);
        } finally {
            setLoading(false);
        }
    }

    async function confirmSelectedAction() {
        if (!selectedAction || actionPending) {
            return;
        }

        setActionPending(true);

        try {
            const result = await postCopilotAction({
                action: selectedAction,
                conversation_id: conversationId,
            });

            const { credential: nextCredential, ...actionResult } = result;

            setItems((current) => [
                ...current,
                {
                    id: timelineId('action-result'),
                    role: 'action-result',
                    result: actionResult,
                },
            ]);

            if (nextCredential) {
                setCredential(nextCredential);
            }

            setSelectedAction(null);
        } catch (error) {
            setItems((current) => [
                ...current,
                {
                    id: timelineId('action-error'),
                    role: 'error',
                    content:
                        error instanceof Error
                            ? error.message
                            : 'No pude ejecutar la acción confirmada.',
                },
            ]);
        } finally {
            setActionPending(false);
        }
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="min-h-0 flex-1 overflow-y-auto bg-muted/20">
                {items.length === 0 ? (
                    <CopilotEmptyState
                        canViewUsers={canViewUsers}
                        canAssignRoles={canAssignRoles}
                        canExecuteCopilot={
                            Boolean(copilot?.can_execute) && canExecuteCopilot
                        }
                        subjectUser={subjectUser}
                        onSelectPrompt={(prompt) => submitPrompt(prompt)}
                    />
                ) : (
                    <>
                        <CopilotMessageList
                            items={items}
                            canConfirmActions={
                                Boolean(copilot?.can_execute) &&
                                canExecuteCopilot
                            }
                            onConfirmAction={setSelectedAction}
                            onSelectPrompt={(prompt) => submitPrompt(prompt)}
                        />
                        {loading && (
                            <div className="space-y-3 px-4 pb-4">
                                <Skeleton className="h-16 w-[78%] rounded-2xl" />
                                <Skeleton className="h-28 w-[88%] rounded-2xl" />
                            </div>
                        )}
                    </>
                )}

                <div ref={endRef} />
            </div>

            {credential && (
                <CopilotCredentialCard
                    credential={credential}
                    onDismiss={() => setCredential(null)}
                />
            )}

            <CopilotComposer
                value={draft}
                disabled={loading}
                onChange={setDraft}
                onSubmit={() => submitPrompt()}
            />

            <CopilotConfirmActionDialog
                action={selectedAction}
                open={selectedAction !== null}
                loading={actionPending}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelectedAction(null);
                    }
                }}
                onConfirm={confirmSelectedAction}
            />
        </div>
    );
}
