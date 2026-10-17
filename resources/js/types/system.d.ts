/**
 * System module TypeScript types for PRD-05 Access Administration.
 * Covers roles, permissions, and user-with-roles shapes.
 */

import type { PaginatedData } from './ui';

export type RoleData = {
    id: number;
    name: string;
    display_name: string | null;
    description: string | null;
    guard_name: string;
    is_active: boolean;
    permissions_count?: number;
    users_count?: number;
    permissions?: PermissionData[];
    created_at: string;
    updated_at: string;
};

export type PermissionData = {
    id: number;
    name: string;
    display_name: string | null;
    description: string | null;
    guard_name: string;
    created_at: string;
    updated_at: string;
};

/** Permissions grouped by context key (first two dot-segments of `name`). */
export type GroupedPermissions = Record<string, PermissionData[]>;

export type EffectivePermission = {
    id: number;
    name: string;
    display_name: string | null;
    /** Map of role_name → role_display_name that grants this permission. */
    roles: Record<string, string>;
};

export type GroupedEffectivePermissions = Record<string, EffectivePermission[]>;

export type UserWithRoles = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    email_verified_at: string | null;
    two_factor_confirmed_at: string | null;
    roles: RoleData[];
    last_login_at?: string | null;
    created_at: string;
    updated_at: string;
};

export type BulkActionPayload = {
    action: 'deactivate' | 'activate' | 'delete';
    ids: number[];
};

export type PaginatedRoles = PaginatedData<RoleData>;
export type PaginatedUsers = PaginatedData<UserWithRoles>;
export type PaginatedPermissions = PaginatedData<PermissionData>;

export type AuditSource = 'model' | 'security';
export type AuditFilterSource = 'all' | AuditSource;

export type AuditMetadataItem = {
    key: string;
    label: string;
    value: unknown;
};

export type AuditIndexEvent = {
    id: string;
    source: AuditSource;
    source_record_id: number;
    timestamp: string | null;
    actor_name: string | null;
    actor_id: number | null;
    actor_href: string | null;
    event: string;
    event_label: string;
    subject_type: string | null;
    subject_id: number | null;
    subject_label: string | null;
    subject_href: string | null;
    ip_address: string | null;
    source_label: string;
    source_badge_variant: AuditSource;
};

export type ModelAuditDetail = AuditIndexEvent & {
    source: 'model';
    old_values: Record<string, unknown>;
    new_values: Record<string, unknown>;
    url: string | null;
    user_agent: string | null;
    tags: string | null;
};

export type SecurityAuditDetail = AuditIndexEvent & {
    source: 'security';
    metadata: AuditMetadataItem[];
    correlation_id: string | null;
};

export type AuditDetail = ModelAuditDetail | SecurityAuditDetail;

export type AuditFilters = {
    source: AuditFilterSource;
    from: string;
    to: string;
    user_id?: string;
    event?: string;
    auditable_type?: string;
    auditable_id?: string;
    sort: 'timestamp' | 'actor_name' | 'subject_label' | 'ip_address';
    direction: 'asc' | 'desc';
};

export type AuditFilterOption = {
    value: string;
    label: string;
};

export type AuditFilterOptions = {
    sources: AuditFilterOption[];
    events: AuditFilterOption[];
    users: AuditFilterOption[];
    auditableTypes: AuditFilterOption[];
    sortableColumns: AuditFilters['sort'][];
};

export type PaginatedAuditEvents = PaginatedData<AuditIndexEvent>;
