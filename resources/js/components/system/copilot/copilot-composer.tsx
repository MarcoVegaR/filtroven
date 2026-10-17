import { SendHorizontal } from 'lucide-react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

type CopilotComposerProps = {
    value: string;
    disabled?: boolean;
    onChange: (value: string) => void;
    onSubmit: () => void;
};

export function CopilotComposer({
    value,
    disabled = false,
    onChange,
    onSubmit,
}: CopilotComposerProps) {
    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        onSubmit();
    }

    return (
        <form onSubmit={handleSubmit} className="border-t bg-background/95 p-4">
            <div className="flex flex-col gap-3 rounded-xl border bg-card p-3 shadow-sm">
                <Textarea
                    id="copilot-prompt"
                    name="copilot_prompt"
                    data-test="copilot-prompt"
                    data-testid="copilot-prompt"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    placeholder="Pregunta por usuarios inactivos, permisos efectivos o el contexto de un usuario..."
                    className="min-h-24 resize-none border-0 px-0 py-0 shadow-none focus-visible:ring-0"
                    disabled={disabled}
                    onKeyDown={(event) => {
                        if (
                            (event.metaKey || event.ctrlKey) &&
                            event.key === 'Enter'
                        ) {
                            event.preventDefault();
                            onSubmit();
                        }
                    }}
                />

                <div className="flex items-center justify-between gap-3">
                    <p className="text-xs text-muted-foreground">
                        Solo lectura. Usa{' '}
                        <span className="font-medium">Ctrl/Cmd + Enter</span>{' '}
                        para enviar.
                    </p>

                    <Button
                        data-test="copilot-submit"
                        data-testid="copilot-submit"
                        type="submit"
                        size="sm"
                        disabled={disabled || !value.trim()}
                    >
                        <SendHorizontal className="size-4" />
                        Enviar
                    </Button>
                </div>
            </div>
        </form>
    );
}
