<?php

namespace App\Ai\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class UsersCopilotDomainLexicon
{
    /**
     * Vocabulario canónico controlado para fuzzy matching.
     * Estos términos se usan para corregir typos de hasta 2 caracteres de distancia.
     */
    private const CANONICAL_VOCABULARY = [
        // Términos de estado
        'activo', 'activos', 'inactivo', 'inactivos',
        // Términos de consulta
        'usuario', 'usuarios', 'usuaria', 'usuarias',
        'permiso', 'permisos',
        'rol', 'roles',
        // Términos de acción (infinitivos y formas verbales)
        'activar', 'activa', 'activas',
        'desactivar', 'desactiva', 'desactivas',
        'reactivar', 'reactiva', 'reactivas',
        'restablecer', 'restablece', 'restableces',
        'crear', 'crea', 'creas',
        'enviar', 'envia', 'envias',
        // NOTA: No incluir 'admin'/'administrador' aquí porque son comunes en nombres de usuario
        // y el typo-correction interferiría con la búsqueda de entidades.
        // La normalización (método normalize()) ya maneja las variaciones para detección de roles.
    ];

    public static function normalize(string $value): string
    {
        $normalized = Str::of(Str::lower(Str::ascii($value)))->squish()->value();

        $replacements = [
            '/\bcom\s+permisos\b/u' => 'con permisos',
            '/\badmins?\b/u' => 'admin',
            '/\badministrad(?:or|ora|ores|oras)\b/u' => 'admin',
            '/\badminsitrad(?:or|ora|ores|oras)\b/u' => 'admin',
            '/\bsuper\s+admin\b/u' => 'super-admin',
            '/\bsuper\s+administrad(?:or|ora|ores|oras)\b/u' => 'super-admin',
            '/\bprivilegios?\b/u' => 'permisos',
            '/\bniveles?\s+de\s+acceso\b/u' => 'acceso efectivo',
            '/\bpermisos?\s+de\s+admin\b/u' => 'acceso administrativo',
            '/\bacceso\s+de\s+admin\b/u' => 'acceso administrativo',
            '/\bacceso\s+admin\b/u' => 'acceso administrativo',
            '/\bacceso\s+administrativ[oa]\b/u' => 'acceso administrativo',
            '/\bpermisos?\s+de\s+super-admin\b/u' => 'rol super-admin',
            '/\bacceso\s+de\s+super-admin\b/u' => 'rol super-admin',
            '/\bno\s+tienen\s+roles\b/u' => 'sin roles',
            '/\bno\s+tiene\s+roles\b/u' => 'sin roles',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized) ?? $normalized;
        }

        return Str::of($normalized)->squish()->value();
    }

    public static function canonicalRole(string $value): string
    {
        $normalized = self::normalize($value);

        return match (true) {
            preg_match('/\bsuper-admin\b/u', $normalized) === 1 => 'super-admin',
            preg_match('/\badmin\b/u', $normalized) === 1 => 'admin',
            default => trim($normalized),
        };
    }

    public static function accessProfile(string $value): ?string
    {
        $normalized = self::normalize($value);

        if (preg_match('/\b(rol|perfil)\s+admin\b/u', $normalized) === 1) {
            return null;
        }

        return match (true) {
            preg_match('/\bacceso\s+administrativo|permisos\s+administrativos|usuarios\s+admin\b/u', $normalized) === 1 => 'administrative_access',
            preg_match('/\badministradores\s+del\s+sistema\b/u', $normalized) === 1 => 'administrative_access',
            preg_match('/\badmin\s+del\s+sistema\b/u', $normalized) === 1 => 'administrative_access',
            preg_match('/\busuarios\s+admin\b/u', $normalized) === 1 => 'administrative_access',
            preg_match('/\badmin\b/u', $normalized) === 1 && preg_match('/\b(rol|super-admin)\b/u', $normalized) !== 1 => 'administrative_access',
            preg_match('/\brol\s+super-admin\b/u', $normalized) === 1 => 'super_admin_role',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function roleSearchTerms(string $value): array
    {
        $canonical = self::canonicalRole($value);

        return match ($canonical) {
            'admin' => ['admin'],
            default => $canonical === '' ? [] : [$canonical],
        };
    }

    public static function permissionNameForIntent(string $value): ?string
    {
        $normalized = self::normalize($value);

        $map = [
            'system.roles.create' => ['crear roles', 'crear rol'],
            'system.roles.update' => ['editar roles', 'actualizar roles', 'modificar roles'],
            'system.users.create' => ['crear usuarios', 'crear usuario'],
            'system.users.view' => ['ver usuarios', 'consultar usuarios'],
            'system.users.update' => ['editar usuarios', 'editar usuario', 'actualizar usuarios', 'modificar usuarios'],
            'system.users.deactivate' => ['desactivar usuarios', 'desactivar usuario', 'inhabilitar usuarios'],
            'system.users.send-reset' => ['enviar restablecimiento', 'enviar reset', 'restablecer contrasena', 'restablecer contrasenas', 'resetear contrasena', 'resetear contrasenas'],
            'system.users.assign-role' => ['asignar roles', 'quitar roles', 'cambiar roles', 'gestionar roles'],
        ];

        foreach ($map as $permission => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($normalized, $phrase)) {
                    return $permission;
                }
            }
        }

        return null;
    }

    public static function permissionLabel(string $permission): string
    {
        return Arr::get([
            'system.roles.create' => 'crear roles',
            'system.roles.update' => 'editar roles',
            'system.users.create' => 'crear usuarios',
            'system.users.view' => 'ver usuarios',
            'system.users.update' => 'editar usuarios',
            'system.users.deactivate' => 'desactivar usuarios',
            'system.users.send-reset' => 'enviar restablecimientos de contraseña',
            'system.users.assign-role' => 'asignar o quitar roles a usuarios',
        ], $permission, $permission);
    }

    /**
     * Corrige typos en tokens del prompt normalizado usando fuzzy matching.
     * Solo aplica a vocabulario canónico controlado con levenshtein <= 2.
     * Excluye: emails (contienen @), tokens de longitud < 4.
     *
     * @param  string  $normalized  Prompt ya normalizado (lowercase, ascii, squished)
     * @return string Prompt con typos corregidos
     */
    public static function correctTypos(string $normalized): string
    {
        $tokens = explode(' ', $normalized);
        $corrected = [];

        foreach ($tokens as $token) {
            $corrected[] = self::correctToken($token);
        }

        return implode(' ', $corrected);
    }

    /**
     * Corrige un token individual si es similar a un término canónico.
     * Preserva la puntuación al final del token (comas, puntos, etc.).
     */
    private static function correctToken(string $token): string
    {
        // Excluir emails (contienen @)
        if (str_contains($token, '@')) {
            return $token;
        }

        // Excluir tokens cortos
        if (mb_strlen($token) < 4) {
            return $token;
        }

        // Separar puntuación trailing para preservarla
        $stripped = rtrim($token, '.,;:!?');
        $punctuation = substr($token, strlen($stripped));

        // Si después de quitar puntuación queda muy corto, no corregir
        if (mb_strlen($stripped) < 4) {
            return $token;
        }

        // Excluir palabras comunes del español que podrían confundirse con términos del dominio
        static $stopWords = ['solo', 'sola', 'solos', 'solas', 'pero', 'como', 'cosa', 'caso',
            'role', 'este', 'esta', 'esos', 'esas', 'todo', 'toda', 'todos', 'todas',
            'solo', 'otro', 'otra', 'otros', 'otras', 'para', 'cada', 'nada', 'algo',
            'creo', 'dime', 'dame', 'hace', 'hola', 'bien', 'malo', 'mala', 'pues',
            // Fix Fase 5: verbos de seguridad criticos que NO deben ser corregidos.
            // `levenshtein('entrar', 'enviar') = 2` los confundia y rompia el
            // denial de impersonation. Preservamos ademas variantes coloquiales
            // de acciones destructivas y secretos que el equipo auditoria.
            'entra', 'entrar', 'entrame', 'entrarle', 'entras',
            'accede', 'acceder', 'accedele', 'accedeme',
            'ingresa', 'ingresar', 'ingresale', 'ingresame',
            'hash', 'secreto', 'secretos', 'autenticador', 'autenticadores',
            'codigo', 'codigos',
            'borra', 'borrar', 'borrame', 'borralo', 'borrala', 'borrales',
            'elimina', 'eliminar', 'eliminame', 'eliminalo', 'eliminala',
            'quita', 'quitar', 'quitame', 'quitalo', 'quitala',
            // Fix Fase 5: verbos naturales de alta. Antes eran corregidos por
            // proximidad levenshtein a `creas/crear`, bloqueando el detector
            // de create con lenguaje humano.
            'agrega', 'agregar', 'agregalo', 'agregala', 'agregame',
            'carga', 'cargar', 'cargalo', 'cargala', 'cargame',
            'incorpora', 'incorporar', 'incorporalo', 'incorporala',
            'suma', 'sumar', 'sumame', 'sumalo', 'sumala',
            'registra', 'registrar', 'registrame', 'registralo', 'registrala',
            'armalo', 'armala', 'hacelo', 'hacela',
            // Fix Fase 5: `creame` se corrige a `crear` (dist=2), rompiendo
            // regex literales como "creame el usuario". Lo preservamos.
            'creame', 'crealo', 'creala', 'creales',
        ];
        if (in_array($stripped, $stopWords, true)) {
            return $token;
        }

        // Buscar el término canónico más cercano
        $bestMatch = null;
        $bestDistance = PHP_INT_MAX;

        foreach (self::CANONICAL_VOCABULARY as $canonical) {
            $distance = levenshtein($stripped, $canonical);

            // Solo considerar si la distancia es <= 2 y menor que la actual
            // Además, exigir que las longitudes sean similares (diff <= 1)
            // para evitar falsos positivos como "solo" → "rol"
            $lengthDiff = abs(mb_strlen($stripped) - mb_strlen($canonical));
            if ($distance <= 2 && $distance < $bestDistance && $lengthDiff <= 1) {
                $bestDistance = $distance;
                $bestMatch = $canonical;
            }
        }

        // Solo corregir si hay match y la distancia es menor a la longitud del token
        // (evita corregir palabras muy diferentes)
        if ($bestMatch !== null && $bestDistance < mb_strlen($stripped)) {
            return $bestMatch.$punctuation;
        }

        return $token;
    }
}
