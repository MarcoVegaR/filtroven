import UsersCopilotActionController from '@/actions/App/Http/Controllers/System/UsersCopilotActionController';
import UsersCopilotMessageController from '@/actions/App/Http/Controllers/System/UsersCopilotMessageController';

export type CopilotCapability = {
    enabled: boolean;
    module_enabled: boolean;
    channel_enabled: boolean;
    can_view: boolean;
    can_execute: boolean;
};

export type CopilotReference = {
    label: string;
    href: string | null;
};

export type CopilotIntent =
    | 'help'
    | 'metrics'
    | 'search_results'
    | 'user_context'
    | 'action_proposal'
    | 'ambiguous'
    | 'out_of_scope'
    | 'error'
    // Fase 1a: denied como intent diferenciado de ambiguous
    | 'denied'
    // Fase 1c: confirmacion de continuacion con snapshot stale
    | 'continuation_confirm'
    // Fase 3: respuestas parciales honestas (mixed-intent con segmentos no ejecutados)
    | 'partial';

export type CopilotDenialCategory =
    | 'sensitive_data'
    | 'impersonation'
    | 'unsupported_operation'
    | 'unsupported_bulk'
    | 'privilege_escalation'
    | 'bypass_policy';

export type CopilotInterpretationSource =
    | 'deterministic'
    | 'deterministic_denial'
    | 'snapshot_stale'
    | 'llm_rescue'
    | 'provider';

export type CopilotInterpretation = {
    understood_intent: string;
    applied_filters: Record<string, unknown>;
    entity: { type: string; id: number | null; label: string | null } | null;
    source: CopilotInterpretationSource;
    confidence: 'high' | 'medium' | 'low';
    capability_key: string | null;
    intent_family: string | null;
};

export type CopilotResponseSource =
    | 'native_tools'
    | 'local_orchestrator'
    | 'gemini_local_orchestrator'
    | 'fallback';

export type CopilotActionType =
    | 'activate'
    | 'deactivate'
    | 'send_reset'
    | 'create_user';

export type CopilotActionTarget =
    | {
          kind: 'user';
          user_id: number;
          name: string;
          email: string;
          is_active: boolean;
      }
    | {
          kind: 'new_user';
          name: string | null;
          email: string | null;
      };

export type CopilotActionProposal = {
    id: string;
    kind: 'action_proposal';
    action_type: CopilotActionType;
    target: CopilotActionTarget;
    summary: string;
    payload: Record<string, unknown>;
    can_execute: boolean;
    deny_reason: string | null;
    required_permissions: string[];
    created_at: string;
    expires_at: string;
    fingerprint: string;
};

export type CopilotResolutionState =
    | 'resolved'
    | 'partial'
    | 'missing_context'
    | 'clarification_required'
    | 'denied'
    | 'not_understood';

export type CopilotActionBoundary =
    | 'none'
    | 'proposed'
    | 'executable'
    | 'executed'
    | 'blocked';

export type CopilotResolution = {
    state: CopilotResolutionState;
    confidence: 'high' | 'medium' | 'low';
    action_boundary: CopilotActionBoundary;
    understood: Array<Record<string, unknown>>;
    unresolved: Array<Record<string, unknown>>;
    missing: Array<Record<string, unknown> | string>;
    denials: Array<Record<string, unknown>>;
};

export type CopilotCredential = {
    kind: 'one_time_password';
    name: string;
    email: string;
    password: string;
    notice: string | null;
};

export type CopilotActionResult = {
    ok: boolean;
    status: 'success' | 'noop';
    action_type: CopilotActionType;
    summary: string;
    target: CopilotActionTarget;
    meta: {
        module: string;
        channel: string;
        conversation_id: string | null;
    };
    credential?: CopilotCredential;
};

export type CopilotSearchResultUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    email_verified: boolean;
    two_factor_enabled: boolean;
    roles_count: number;
    roles: Array<{
        id: number;
        name: string;
        display_name: string | null;
        is_active: boolean;
    }>;
    created_at: string | null;
    show_href: string | null;
};

export type CopilotSearchResultsCard = {
    kind: 'search_results';
    title: string | null;
    summary: string | null;
    data: {
        count: number;
        visible_count?: number;
        matching_count?: number;
        truncated?: boolean;
        applied_filters?: Record<string, unknown> | null;
        users: CopilotSearchResultUser[];
    };
};

export type CopilotUserContextCard = {
    kind: 'user_context';
    title: string | null;
    summary: string | null;
    data: {
        found: boolean;
        user?: {
            id: number;
            name: string;
            email: string;
            is_active: boolean;
            email_verified: boolean;
            email_verified_at: string | null;
            two_factor_enabled: boolean;
            two_factor_confirmed_at: string | null;
            created_at: string | null;
            updated_at: string | null;
            references: CopilotReference[];
        };
        roles?: Array<{
            id: number;
            name: string;
            display_name: string | null;
            is_active: boolean;
        }>;
        effective_permissions?: Record<
            string,
            Array<{
                id: number;
                name: string;
                display_name: string | null;
                group_key: string;
                roles: Array<{
                    name: string;
                    display_name: string | null;
                }>;
            }>
        >;
    };
};

export type CopilotMetricsCard = {
    kind: 'metrics';
    title: string | null;
    summary: string | null;
    data: {
        capability_key: string | null;
        metric: {
            label: string | null;
            value: number | null;
            unit: 'users' | 'roles';
        };
        breakdown: Array<{
            key: string;
            label: string;
            value: number;
        }>;
        applied_filters: Record<string, unknown> | null;
    };
};

export type CopilotClarificationCard = {
    kind: 'clarification';
    title: string | null;
    summary: string | null;
    data: {
        reason: string;
        question: string;
        options: Array<{
            label: string;
            value: string;
        }>;
    };
};

export type CopilotNoticeCard = {
    kind: 'notice';
    title: string | null;
    summary: string | null;
    data: Record<string, unknown>;
};

export type CopilotDeniedCard = {
    kind: 'denied';
    title: string | null;
    summary: string | null;
    data: {
        category: CopilotDenialCategory;
        reason: string;
        message: string;
        alternatives: Array<{ label: string; prompt: string }>;
    };
};

export type CopilotContinuationConfirmCard = {
    kind: 'continuation_confirm';
    title: string | null;
    summary: string | null;
    data: {
        freshness: 'stale' | 'expired';
        question: string;
        entity_label: string | null;
        minutes_elapsed: number | null;
        options: Array<{ label: string; value: string }>;
    };
};

export type CopilotPartialNoticeCard = {
    kind: 'partial_notice';
    title: string | null;
    summary: string | null;
    data: {
        segments: Array<{
            text: string;
            status: 'not_executed' | 'failed' | 'skipped';
            reason: string;
            suggested_follow_up: string | null;
        }>;
    };
};

export type CopilotCard =
    | CopilotNoticeCard
    | CopilotSearchResultsCard
    | CopilotUserContextCard
    | CopilotMetricsCard
    | CopilotClarificationCard
    | CopilotDeniedCard
    | CopilotContinuationConfirmCard
    | CopilotPartialNoticeCard;

export type CopilotResponse = {
    answer: string;
    intent: CopilotIntent;
    cards: CopilotCard[];
    actions: CopilotActionProposal[];
    requires_confirmation: boolean;
    references: CopilotReference[];
    resolution: CopilotResolution;
    // Fase 1b: bloque opcional de explicabilidad. Renderizado como header
    // discreto en el UI cuando existe.
    interpretation?: CopilotInterpretation | null;
    meta: {
        module: string;
        channel: string;
        subject_user_id: number | null;
        fallback: boolean;
        capability_key: string | null;
        intent_family: string | null;
        conversation_state_version: number | null;
        response_source: CopilotResponseSource;
        diagnostics: Record<string, unknown> | null;
    };
};

export type CopilotEnvelope = {
    conversation_id: string;
    response: CopilotResponse;
};

export type CopilotMessagePayload = {
    prompt: string;
    conversation_id?: string;
    subject_user_id?: number;
};

export type CopilotActionPayload = {
    action: CopilotActionProposal;
    conversation_id?: string;
};

function readCookie(name: string): string | null {
    const match = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith(`${name}=`));

    return match
        ? decodeURIComponent(match.split('=').slice(1).join('='))
        : null;
}

function csrfHeaders(): Record<string, string> {
    const xsrfToken = readCookie('XSRF-TOKEN');
    const csrfToken = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return {
        ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
    };
}

function requestErrorMessage(
    body: Record<string, unknown> | null,
    status: number,
): string {
    const validationErrors = body?.errors;

    if (
        validationErrors &&
        typeof validationErrors === 'object' &&
        !Array.isArray(validationErrors)
    ) {
        const firstError = Object.values(validationErrors).find((value) =>
            Array.isArray(value),
        );

        if (Array.isArray(firstError) && typeof firstError[0] === 'string') {
            return firstError[0];
        }
    }

    if (typeof body?.message === 'string') {
        return body.message;
    }

    if (status === 403) {
        return 'No tienes permiso para usar el copiloto de usuarios.';
    }

    return 'No pude enviar la solicitud al copiloto de usuarios.';
}

export async function postCopilotMessage(
    payload: CopilotMessagePayload,
): Promise<CopilotEnvelope> {
    const response = await fetch(UsersCopilotMessageController.url(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        body: JSON.stringify(payload),
    });

    const body = (await response.json().catch(() => null)) as Record<
        string,
        unknown
    > | null;

    if (!response.ok || body === null) {
        throw new Error(requestErrorMessage(body, response.status));
    }

    return body as unknown as CopilotEnvelope;
}

export async function postCopilotAction(
    payload: CopilotActionPayload,
): Promise<CopilotActionResult> {
    const response = await fetch(
        UsersCopilotActionController.url(payload.action.action_type),
        {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeaders(),
            },
            body: JSON.stringify({
                conversation_id: payload.conversation_id,
                proposal_id: payload.action.id,
                fingerprint: payload.action.fingerprint,
                target: payload.action.target,
                payload: payload.action.payload,
            }),
        },
    );

    const body = (await response.json().catch(() => null)) as Record<
        string,
        unknown
    > | null;

    if (!response.ok || body === null) {
        throw new Error(requestErrorMessage(body, response.status));
    }

    return body as unknown as CopilotActionResult;
}
