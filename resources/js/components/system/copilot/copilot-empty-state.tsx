import {
    ArrowRight,
    BarChart3,
    Bot,
    KeyRound,
    Search,
    ShieldCheck,
    ShieldQuestion,
    UserRoundSearch,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';

type SubjectUser = {
    id: number;
    name: string;
    email: string;
};

type PromptSuggestion = {
    icon: ComponentType<{ className?: string }>;
    label: string;
    prompt: string;
};

type CopilotEmptyStateProps = {
    canViewUsers: boolean;
    canAssignRoles: boolean;
    canExecuteCopilot?: boolean;
    subjectUser?: SubjectUser | null;
    onSelectPrompt: (prompt: string) => void;
};

function getGeneralPrompts(permissions: {
    canViewUsers: boolean;
    canAssignRoles: boolean;
    canExecuteCopilot: boolean;
}): PromptSuggestion[] {
    const prompts: PromptSuggestion[] = [];

    if (permissions.canViewUsers) {
        prompts.push(
            {
                icon: BarChart3,
                label: '¿Cuántos usuarios hay?',
                prompt: 'Cuantos usuarios hay en total',
            },
            {
                icon: Search,
                label: 'Buscar usuarios inactivos',
                prompt: 'Busca usuarios inactivos',
            },
            {
                icon: Users,
                label: '¿Quiénes son admin?',
                prompt: 'Quienes son los usuarios admin',
            },
            {
                icon: ShieldQuestion,
                label: '¿Quién puede crear roles?',
                prompt: 'Quien puede crear roles',
            },
        );
    }

    if (permissions.canAssignRoles) {
        prompts.push({
            icon: ShieldCheck,
            label: '¿Cuáles roles existen?',
            prompt: 'Cuales roles existen',
        });
    }

    if (permissions.canExecuteCopilot) {
        prompts.push({
            icon: KeyRound,
            label: 'Proponer un restablecimiento',
            prompt: 'Propon enviar un correo de restablecimiento a un usuario que lo necesite.',
        });
    }

    return prompts;
}

function getUserPrompts(
    user: SubjectUser,
    canExecuteCopilot: boolean,
): PromptSuggestion[] {
    const prompts: PromptSuggestion[] = [
        {
            icon: UserRoundSearch,
            label: 'Resume su estado actual',
            prompt: `Resume el estado actual de ${user.name} (${user.email}).`,
        },
        {
            icon: ShieldCheck,
            label: '¿Qué roles y permisos tiene?',
            prompt: `Que permisos tiene ${user.email}`,
        },
        {
            icon: ShieldQuestion,
            label: '¿Qué puede hacer?',
            prompt: `Que puede hacer el usuario ${user.email}`,
        },
    ];

    if (canExecuteCopilot) {
        prompts.push({
            icon: KeyRound,
            label: `Propón desactivar`,
            prompt: `Propon desactivar a ${user.name} (${user.email}) y explica si puedo confirmarlo.`,
        });
    }

    return prompts;
}

function PromptCard({
    suggestion,
    onClick,
}: {
    suggestion: PromptSuggestion;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="group flex w-full items-start gap-3 rounded-xl border border-border/60 bg-card p-3 text-left transition-all hover:border-primary/40 hover:bg-accent/50"
        >
            <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground transition-colors group-hover:bg-primary/10 group-hover:text-primary">
                <suggestion.icon className="size-4" />
            </div>
            <div className="flex min-h-8 flex-1 items-center">
                <span className="text-sm leading-snug text-foreground/80 transition-colors group-hover:text-foreground">
                    {suggestion.label}
                </span>
            </div>
            <ArrowRight className="mt-2 size-3.5 shrink-0 text-muted-foreground/0 transition-all group-hover:text-muted-foreground" />
        </button>
    );
}

export function CopilotEmptyState({
    canViewUsers,
    canAssignRoles,
    canExecuteCopilot = false,
    subjectUser,
    onSelectPrompt,
}: CopilotEmptyStateProps) {
    const prompts = subjectUser
        ? getUserPrompts(subjectUser, canExecuteCopilot)
        : getGeneralPrompts({
              canViewUsers,
              canAssignRoles,
              canExecuteCopilot,
          });

    return (
        <div className="flex h-full flex-col items-center justify-center gap-6 px-5 py-10">
            <div className="flex flex-col items-center gap-3 text-center">
                <div className="flex size-12 items-center justify-center rounded-full bg-primary/10">
                    <Bot className="size-6 text-primary" />
                </div>
                <div className="max-w-sm space-y-1.5">
                    <h3 className="text-base font-semibold text-foreground">
                        Copiloto de usuarios
                    </h3>
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        {subjectUser
                            ? `Puedo resumir el estado de ${subjectUser.name}, revisar sus permisos o proponer acciones sobre su cuenta.`
                            : 'Consulto métricas, busco usuarios, reviso accesos y permisos, y propongo acciones con confirmación.'}
                    </p>
                </div>
            </div>

            {prompts.length > 0 && (
                <div className="w-full max-w-md space-y-3">
                    <p className="text-center text-xs font-medium tracking-wider text-muted-foreground/70 uppercase">
                        Prueba preguntar
                    </p>
                    <div className="grid grid-cols-2 gap-2">
                        {prompts.map((suggestion) => (
                            <PromptCard
                                key={suggestion.label}
                                suggestion={suggestion}
                                onClick={() =>
                                    onSelectPrompt(suggestion.prompt)
                                }
                            />
                        ))}
                    </div>
                    <p className="pt-1 text-center text-xs text-muted-foreground/60">
                        O escribe tu propia pregunta abajo
                    </p>
                </div>
            )}
        </div>
    );
}
