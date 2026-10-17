<?php

namespace App\Enums;

enum SecurityEventType: string
{
    case LoginSuccess = 'login_success';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case TwoFactorEnabled = '2fa_enabled';
    case TwoFactorDisabled = '2fa_disabled';
    case RoleAssigned = 'role_assigned';
    case RoleRevoked = 'role_revoked';

    // User lifecycle events (PRD-05)
    case UserCreated = 'user_created';
    case UserUpdated = 'user_updated';
    case UserDeactivated = 'user_deactivated';
    case UserActivated = 'user_activated';
    case UserDeleted = 'user_deleted';
    case PasswordResetSent = 'password_reset_sent';

    // Role lifecycle events (PRD-05)
    case RoleCreated = 'role_created';
    case RoleUpdated = 'role_updated';
    case RoleDeactivated = 'role_deactivated';
    case RoleActivated = 'role_activated';
    case RoleDeleted = 'role_deleted';
    case PermissionsSynced = 'permissions_synced';

    public function label(): string
    {
        return match ($this) {
            self::LoginSuccess => 'Inicio de sesión',
            self::LoginFailed => 'Intento de sesión fallido',
            self::Logout => 'Cierre de sesión',
            self::TwoFactorEnabled => '2FA habilitado',
            self::TwoFactorDisabled => '2FA deshabilitado',
            self::RoleAssigned => 'Rol asignado',
            self::RoleRevoked => 'Rol revocado',
            self::RoleCreated => 'Rol creado',
            self::RoleUpdated => 'Rol actualizado',
            self::RoleDeactivated => 'Rol desactivado',
            self::RoleActivated => 'Rol activado',
            self::RoleDeleted => 'Rol eliminado',
            self::PermissionsSynced => 'Permisos sincronizados',
            self::UserCreated => 'Usuario creado',
            self::UserUpdated => 'Usuario actualizado',
            self::UserDeactivated => 'Usuario desactivado',
            self::UserActivated => 'Usuario activado',
            self::UserDeleted => 'Usuario eliminado',
            self::PasswordResetSent => 'Restablecimiento enviado',
        };
    }
}
