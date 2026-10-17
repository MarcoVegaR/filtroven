import { usePage } from '@inertiajs/react';
import { Bot, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';

import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCan } from '@/hooks/use-can';
import type { CopilotCapability } from '@/lib/copilot';

import { CopilotPanel } from './copilot-panel';

type SubjectUser = {
    id: number;
    name: string;
    email: string;
};

type CopilotSheetProps = {
    trigger: ReactNode;
    subjectUser?: SubjectUser | null;
};

export function CopilotSheet({ trigger, subjectUser }: CopilotSheetProps) {
    const { copilot } = usePage<{ copilot: CopilotCapability }>().props;
    const canView = useCan('system.users-copilot.view');

    if (
        !copilot?.enabled ||
        !copilot.module_enabled ||
        !copilot.channel_enabled ||
        !copilot.can_view ||
        !canView
    ) {
        return null;
    }

    return (
        <Sheet>
            <SheetTrigger asChild>{trigger}</SheetTrigger>
            <SheetContent className="w-full gap-0 sm:max-w-2xl">
                <SheetHeader className="border-b bg-background px-5 py-4">
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Bot className="size-5" />
                        </div>
                        <div>
                            <SheetTitle className="flex items-center gap-2 text-base">
                                Copiloto de usuarios
                                <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700">
                                    <Sparkles className="size-3" />
                                    Propuestas con confirmación
                                </span>
                            </SheetTitle>
                            <SheetDescription>
                                Consulta estados, accesos y propone acciones
                                confirmadas desde la aplicación.
                            </SheetDescription>
                        </div>
                    </div>
                </SheetHeader>

                <CopilotPanel subjectUser={subjectUser} />
            </SheetContent>
        </Sheet>
    );
}
