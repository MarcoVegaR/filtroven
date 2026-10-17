import { Check, Copy, EyeOff, KeyRound } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { CopilotCredential } from '@/lib/copilot';

type CopilotCredentialCardProps = {
    credential: CopilotCredential;
    onDismiss: () => void;
};

export function CopilotCredentialCard({
    credential,
    onDismiss,
}: CopilotCredentialCardProps) {
    const [copied, setCopied] = useState(false);

    async function copyPassword() {
        await navigator.clipboard.writeText(credential.password);
        setCopied(true);

        window.setTimeout(() => setCopied(false), 2000);
    }

    return (
        <Card className="mx-4 mb-4 border-amber-300/70 bg-amber-50/80 py-4 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-50">
            <CardContent className="space-y-4 px-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2 text-sm font-semibold">
                            <KeyRound className="size-4" />
                            Credenciales temporales
                        </div>
                        <p className="text-sm text-amber-900/80 dark:text-amber-100/80">
                            {credential.notice ??
                                'Guárdalas y compártelas por un canal seguro antes de cerrar esta tarjeta.'}
                        </p>
                    </div>

                    <Badge variant="secondary">Solo una vez</Badge>
                </div>

                <div className="grid gap-3 rounded-xl border border-amber-300/80 bg-background/80 p-3 text-sm text-foreground dark:border-amber-500/20 dark:bg-background/60">
                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Usuario
                        </p>
                        <p className="font-medium">{credential.name}</p>
                        <p className="text-muted-foreground">
                            {credential.email}
                        </p>
                    </div>

                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Contraseña temporal
                        </p>
                        <code className="mt-1 block rounded-lg border bg-muted px-3 py-2 font-mono text-sm">
                            {credential.password}
                        </code>
                    </div>
                </div>

                <div className="flex flex-wrap justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={copyPassword}
                    >
                        {copied ? (
                            <Check className="size-4" />
                        ) : (
                            <Copy className="size-4" />
                        )}
                        {copied ? 'Copiada' : 'Copiar contraseña'}
                    </Button>
                    <Button type="button" size="sm" onClick={onDismiss}>
                        <EyeOff className="size-4" />
                        Ocultar ahora
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
