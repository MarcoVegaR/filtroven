import {
    Eye,
    Footprints,
    KeyRound,
    LayoutDashboard,
    ScrollText,
    Settings,
    ShieldCheck,
    ShieldEllipsis,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * Maps backend icon name strings to Lucide React components.
 * Used by sidebar and header navigation to resolve serialized icon names.
 */
const iconMap: Record<string, LucideIcon> = {
    'layout-dashboard': LayoutDashboard,
    'shield-check': ShieldCheck,
    users: Users,
    eye: Eye,
    'scroll-text': ScrollText,
};

export function resolveIcon(name?: string | null): LucideIcon | undefined {
    if (!name) {
        return undefined;
    }

    return iconMap[name];
}

/**
 * Friendly labels for permission group keys.
 * Centralised so every component (show pages, pickers, etc.) stays consistent.
 */
const groupLabels: Record<string, string> = {
    'system.roles': 'Gestión de Roles',
    'system.users': 'Gestión de Usuarios',
    'system.permissions': 'Gestión de Permisos',
    'system.audit': 'Auditoría',
};

export function groupLabel(key: string): string {
    return groupLabels[key] ?? key;
}

const modelAuditEventLabels: Record<string, string> = {
    created: 'Creación',
    updated: 'Actualización',
    deleted: 'Eliminación',
    restored: 'Restauración',
};

const securityEventLabels: Record<string, string> = {
    login_success: 'Inicio de sesión',
    login_failed: 'Intento de sesión fallido',
    logout: 'Cierre de sesión',
    '2fa_enabled': '2FA habilitado',
    '2fa_disabled': '2FA deshabilitado',
    role_assigned: 'Rol asignado',
    role_revoked: 'Rol revocado',
    role_created: 'Rol creado',
    role_updated: 'Rol actualizado',
    role_deactivated: 'Rol desactivado',
    role_activated: 'Rol activado',
    role_deleted: 'Rol eliminado',
    permissions_synced: 'Permisos sincronizados',
    user_created: 'Usuario creado',
    user_updated: 'Usuario actualizado',
    user_deactivated: 'Usuario desactivado',
    user_activated: 'Usuario activado',
    user_deleted: 'Usuario eliminado',
    password_reset_sent: 'Restablecimiento enviado',
};

const auditableTypeLabels: Record<string, string> = {
    User: 'Usuario',
    Role: 'Rol',
    Permission: 'Permiso',
    'App\\Models\\User': 'Usuario',
    'App\\Models\\Role': 'Rol',
    'App\\Models\\Permission': 'Permiso',
};

const metadataKeyLabels: Record<string, string> = {
    email_attempted: 'Email intentado',
    email: 'Email',
    role: 'Rol',
    assigned_by: 'Asignado por',
    revoked_by: 'Revocado por',
    created_user_id: 'Usuario creado',
    updated_user_id: 'Usuario actualizado',
    deleted_user_id: 'Usuario eliminado',
    role_id: 'ID del rol',
};

export function modelAuditEventLabel(value: string): string {
    return modelAuditEventLabels[value] ?? value;
}

export function securityEventLabel(value: string): string {
    return securityEventLabels[value] ?? value;
}

export function auditableTypeLabel(value?: string | null): string {
    if (!value) {
        return 'Sin entidad';
    }

    return auditableTypeLabels[value] ?? value;
}

export function metadataKeyLabel(value: string): string {
    return metadataKeyLabels[value] ?? value.replaceAll('_', ' ');
}

type HelpCategoryMeta = {
    icon: LucideIcon;
    description: string;
};

const helpCategoryMeta: Record<string, HelpCategoryMeta> = {
    'first-steps': {
        icon: Footprints,
        description:
            'Lo básico para empezar a trabajar en el sistema desde el primer día.',
    },
    users: {
        icon: Users,
        description:
            'Crea, edita y gestiona las cuentas de las personas que usan el sistema.',
    },
    'roles-and-permissions': {
        icon: ShieldEllipsis,
        description:
            'Controla quién puede hacer qué, creando roles y asignando permisos.',
    },
    'security-access': {
        icon: KeyRound,
        description:
            'Revisa tu propio acceso y entiende cómo funcionan los permisos de tu cuenta.',
    },
    settings: {
        icon: Settings,
        description:
            'Ajusta tu perfil, contraseña, verificación en dos pasos y apariencia.',
    },
    audit: {
        icon: ScrollText,
        description:
            'Consulta el historial de actividad: quién hizo qué y cuándo.',
    },
};

export function helpCategoryIcon(key: string): LucideIcon | undefined {
    return helpCategoryMeta[key]?.icon;
}

export function helpCategoryDescription(key: string): string {
    return (
        helpCategoryMeta[key]?.description ??
        'Guías operativas para tareas frecuentes de esta área.'
    );
}
