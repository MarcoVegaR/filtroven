import { usePage } from '@inertiajs/react';
import type { Auth } from '@/types/auth';

/**
 * useCan — UI-only authorization reflection hook.
 *
 * Reads `auth.permissions` from the Inertia shared page props and returns
 * a boolean indicating whether the authenticated user has the given permission.
 *
 * IMPORTANT: This hook is for UI convenience only (show/hide elements).
 * Backend enforcement via Policies, Gates, and FormRequest::authorize() is
 * ALWAYS required. Never rely on this hook as a security boundary.
 *
 * @example
 *   const canCreate = useCan('system.users.create');
 *   return canCreate ? <CreateButton /> : null;
 */
export function useCan(permission: string): boolean {
    const props = usePage<{ auth: Auth }>().props;
    const permissions = props.auth?.permissions;

    if (!Array.isArray(permissions)) {
        return false;
    }

    return permissions.includes(permission);
}

export default useCan;
