<?php

namespace App\Ai\Support;

/**
 * Catalogo centralizado de mensajes y alternativas por categoria de denegacion.
 *
 * Introducido en Fase 1a para romper la mezcla semantica entre `denied` y
 * `ambiguous`. Cada categoria define:
 * - message: texto canonico del rechazo.
 * - alternatives[]: prompts alternativos legitimos que el UI expone como
 *   botones clickables para redirigir al usuario.
 */
class CopilotDenialCatalog
{
    public const CATEGORY_SENSITIVE_DATA = 'sensitive_data';

    public const CATEGORY_IMPERSONATION = 'impersonation';

    public const CATEGORY_UNSUPPORTED_OPERATION = 'unsupported_operation';

    public const CATEGORY_UNSUPPORTED_BULK = 'unsupported_bulk';

    public const CATEGORY_PRIVILEGE_ESCALATION = 'privilege_escalation';

    public const CATEGORY_BYPASS_POLICY = 'bypass_policy';

    /**
     * @return array<string, array{message: string, alternatives: list<array{label: string, prompt: string}>}>
     */
    public static function all(): array
    {
        return [
            self::CATEGORY_SENSITIVE_DATA => [
                'message' => 'No puedo mostrar contrasenas, tokens ni datos de autenticacion. Si necesitas restablecer el acceso de un usuario, puedo proponerlo.',
                'alternatives' => [
                    [
                        'label' => 'Proponer restablecimiento de contrasena',
                        'prompt' => 'Propon enviar un correo de restablecimiento de contrasena',
                    ],
                    [
                        'label' => 'Explicar como funciona 2FA',
                        'prompt' => 'Como funciona el 2FA',
                    ],
                ],
            ],
            self::CATEGORY_IMPERSONATION => [
                'message' => 'No puedo iniciar sesion como otro usuario ni acceder a cuentas ajenas.',
                'alternatives' => [
                    [
                        'label' => 'Revisar permisos de un usuario',
                        'prompt' => 'Resume el estado actual de un usuario',
                    ],
                ],
            ],
            self::CATEGORY_UNSUPPORTED_OPERATION => [
                'message' => 'Esa operacion no esta disponible desde el copiloto. Puedo ayudarte a consultar, buscar usuarios o proponer acciones como activar, desactivar o restablecer contrasena.',
                'alternatives' => [
                    [
                        'label' => 'Buscar usuarios',
                        'prompt' => 'Busca usuarios inactivos',
                    ],
                    [
                        'label' => 'Proponer desactivar a un usuario',
                        'prompt' => 'Propon desactivar a un usuario',
                    ],
                ],
            ],
            self::CATEGORY_UNSUPPORTED_BULK => [
                'message' => 'No puedo ejecutar acciones masivas desde el copiloto. Indica un usuario especifico para continuar.',
                'alternatives' => [
                    [
                        'label' => 'Listar usuarios con rol admin',
                        'prompt' => 'Que usuarios tienen rol admin',
                    ],
                ],
            ],
            self::CATEGORY_PRIVILEGE_ESCALATION => [
                'message' => 'No puedo conceder acceso total ni elevar privilegios desde el copiloto sin una politica y permisos validos.',
                'alternatives' => [
                    [
                        'label' => 'Revisar roles disponibles',
                        'prompt' => 'Muestrame los roles disponibles',
                    ],
                    [
                        'label' => 'Explicar permisos de un usuario',
                        'prompt' => 'Explica que permisos tiene un usuario',
                    ],
                ],
            ],
            self::CATEGORY_BYPASS_POLICY => [
                'message' => 'No puedo saltarme validaciones, permisos ni politicas de seguridad.',
                'alternatives' => [
                    [
                        'label' => 'Revisar capacidades permitidas',
                        'prompt' => 'Solo dime que acciones puede hacer el copiloto de usuarios',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{message: string, alternatives: list<array{label: string, prompt: string}>}
     */
    public static function forCategory(string $category): array
    {
        return self::all()[$category] ?? self::all()[self::CATEGORY_UNSUPPORTED_OPERATION];
    }

    /**
     * Extrae la categoria desde un reason tipo `denied:xxx` o directamente xxx.
     */
    public static function categoryFromReason(?string $reason): string
    {
        if (! is_string($reason) || $reason === '') {
            return self::CATEGORY_UNSUPPORTED_OPERATION;
        }

        $category = str_starts_with($reason, 'denied:')
            ? substr($reason, 7)
            : $reason;

        if (in_array($category, CopilotStructuredOutput::DENIAL_CATEGORIES, true)) {
            return $category;
        }

        return self::CATEGORY_UNSUPPORTED_OPERATION;
    }
}
