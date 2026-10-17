<?php

use App\Ai\Support\UsersCopilotDomainLexicon;

describe('UsersCopilotDomainLexicon', function (): void {
    describe('correctTypos', function (): void {
        it('corrige typo usuari a usuario', function (): void {
            $result = UsersCopilotDomainLexicon::correctTypos('usuari');
            expect($result)->toBe('usuario');
        });

        it('corrige typo usurio a usuario', function (): void {
            $result = UsersCopilotDomainLexicon::correctTypos('usurio');
            expect($result)->toBe('usuario');
        });

        it('corrige typo permisso a permiso', function (): void {
            $result = UsersCopilotDomainLexicon::correctTypos('permisso');
            expect($result)->toBe('permiso');
        });

        it('corrige typo desactibar a desactivar', function (): void {
            $result = UsersCopilotDomainLexicon::correctTypos('desactibar');
            expect($result)->toBe('desactivar');
        });

        it('corrige typo activarr a activar', function (): void {
            $result = UsersCopilotDomainLexicon::correctTypos('activarr');
            expect($result)->toBe('activar');
        });

        it('no corrige adminitrador (término de nombre propio, no vocabulario)', function (): void {
            // Los términos "admin/administrador" fueron removidos del vocabulario canónico
            // para evitar que nombres de usuarios como "Administrador" se auto-corrigan
            // durante la búsqueda de entidades
            $result = UsersCopilotDomainLexicon::correctTypos('adminitrador');
            expect($result)->toBe('adminitrador'); // Sin corrección
        });

        it('no modifica emails (contienen @)', function (): void {
            $result = UsersCopilotDomainLexicon::correctTypos('busca a usuario@example.com');
            expect($result)->toBe('busca a usuario@example.com');
        });

        it('no modifica tokens cortos (< 4 caracteres)', function (): void {
            $result = UsersCopilotDomainLexicon::correctTypos('el usuario');
            expect($result)->toBe('el usuario');
        });

        it('no corrige terminos cercanos pero semanticamente distintos (activo vs activar)', function (): void {
            // "activo" es un término canónico (estado), no debería corregirse a "activar" (acción)
            $result = UsersCopilotDomainLexicon::correctTypos('usuarios activos');
            expect($result)->toBe('usuarios activos');
        });

        it('no introduce ruido en terminos canonicos exactos', function (): void {
            $result = UsersCopilotDomainLexicon::correctTypos('buscar usuarios administradores activos');
            expect($result)->toBe('buscar usuarios administradores activos');
        });

        it('corrige multiples typos en el mismo prompt', function (): void {
            $result = UsersCopilotDomainLexicon::correctTypos('usuaris permisos inactiv');
            // 'usuaris' puede corregirse a 'usuario' o 'usuarios' (ambos a distancia 1)
            // El resultado exacto depende del orden en el vocabulario canónico
            expect($result)->toBeIn(['usuario permisos inactivo', 'usuarios permisos inactivo']);
        });

        it('no corrige palabras que no estan en el vocabulario canonico', function (): void {
            // "Carlos" no está en el vocabulario canónico
            $result = UsersCopilotDomainLexicon::correctTypos('buscar usuario carlos');
            expect($result)->toBe('buscar usuario carlos');
        });
    });

    describe('normalize', function (): void {
        it('normaliza texto correctamente', function (): void {
            $result = UsersCopilotDomainLexicon::normalize('  USUARIOS   ACTIVOS  ');
            expect($result)->toBe('usuarios activos');
        });

        it('convierte admin variations a admin', function (): void {
            $result = UsersCopilotDomainLexicon::normalize('administradores');
            expect($result)->toBe('admin');
        });
    });

    describe('canonicalRole', function (): void {
        it('retorna admin para admin variations', function (): void {
            expect(UsersCopilotDomainLexicon::canonicalRole('admin'))->toBe('admin');
            expect(UsersCopilotDomainLexicon::canonicalRole('administrador'))->toBe('admin');
            expect(UsersCopilotDomainLexicon::canonicalRole('super-admin'))->toBe('super-admin');
        });
    });
});
