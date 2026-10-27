export const loginContent = {
    title: 'Inicia sesión',
    primaryActionLabel: 'Iniciar sesión',
    forgotPasswordLabel: '¿Olvidaste tu contraseña?',
    rememberLabel: 'Mantener mi sesión',
} as const;

export const forgotPasswordContent = {
    title: 'Recuperar contraseña',
    description: 'Ingresa tu correo para recibir un enlace de restablecimiento',
    primaryActionLabel: 'Enviar enlace de recuperación',
    secondaryActionPrefix: 'O vuelve a',
    secondaryActionLabel: 'iniciar sesión',
} as const;

export const verifyEmailContent = {
    title: 'Verifica tu correo',
    description:
        'Revisa tu bandeja de entrada y confirma tu correo electrónico para continuar.',
    primaryActionLabel: 'Reenviar correo de verificación',
    secondaryActionLabel: 'Cerrar sesión',
    statusMessage:
        'Te enviamos un nuevo enlace de verificación al correo asociado a tu cuenta.',
} as const;

export const resetPasswordContent = {
    title: 'Restablecer contraseña',
    description: 'Ingresa tu nueva contraseña para completar el acceso',
    submitLabel: 'Restablecer contraseña',
} as const;

export const confirmPasswordContent = {
    title: 'Confirma tu contraseña',
    description:
        'Esta es una zona segura del sistema. Confirma tu contraseña antes de continuar.',
    submitLabel: 'Confirmar contraseña',
} as const;
