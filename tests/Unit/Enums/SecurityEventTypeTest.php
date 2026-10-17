<?php

use App\Enums\SecurityEventType;

it('returns the expected spanish labels for every security event type', function () {
    expect(SecurityEventType::LoginSuccess->label())->toBe('Inicio de sesión')
        ->and(SecurityEventType::LoginFailed->label())->toBe('Intento de sesión fallido')
        ->and(SecurityEventType::Logout->label())->toBe('Cierre de sesión')
        ->and(SecurityEventType::TwoFactorEnabled->label())->toBe('2FA habilitado')
        ->and(SecurityEventType::TwoFactorDisabled->label())->toBe('2FA deshabilitado')
        ->and(SecurityEventType::RoleAssigned->label())->toBe('Rol asignado')
        ->and(SecurityEventType::RoleRevoked->label())->toBe('Rol revocado')
        ->and(SecurityEventType::RoleCreated->label())->toBe('Rol creado')
        ->and(SecurityEventType::RoleUpdated->label())->toBe('Rol actualizado')
        ->and(SecurityEventType::RoleDeactivated->label())->toBe('Rol desactivado')
        ->and(SecurityEventType::RoleActivated->label())->toBe('Rol activado')
        ->and(SecurityEventType::RoleDeleted->label())->toBe('Rol eliminado')
        ->and(SecurityEventType::PermissionsSynced->label())->toBe('Permisos sincronizados')
        ->and(SecurityEventType::UserCreated->label())->toBe('Usuario creado')
        ->and(SecurityEventType::UserUpdated->label())->toBe('Usuario actualizado')
        ->and(SecurityEventType::UserDeactivated->label())->toBe('Usuario desactivado')
        ->and(SecurityEventType::UserActivated->label())->toBe('Usuario activado')
        ->and(SecurityEventType::UserDeleted->label())->toBe('Usuario eliminado')
        ->and(SecurityEventType::PasswordResetSent->label())->toBe('Restablecimiento enviado');
});
