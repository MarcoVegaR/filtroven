# PRD 1 - Personalizacion base corporativa del boilerplate

> Nota de secuencia: este archivo se mantiene con el nombre historico `PRD.md` y debe leerse como el PRD-01 completado. Se conserva asi como alias retrocompatible para no romper referencias existentes mientras `PRD-00.md` cumple el rol de documento de gobierno y alcance del producto.

## Problema

El proyecto parte de un starter kit fresco de Laravel 13 pensado como base publica y generica. En su estado actual, todavia conserva comportamientos, textos, branding y flujos que no corresponden a un sistema web interno desarrollado por `Caracoders Pro Services`.

Los principales problemas a resolver son:

- el sistema permite o expone flujos de autoregistro que no deben existir
- la aplicacion sigue mostrando una welcome page publica que no tiene sentido para este tipo de sistema
- la UI, validaciones y flujos de autenticacion siguen mayormente en ingles
- el branding visual todavia responde al starter de Laravel y no a la identidad del boilerplate corporativo
- existen funcionalidades visibles como `Delete account` que no forman parte del baseline deseado
- no existe todavia un usuario administrador inicial listo para el entorno local

## Objetivos

- Convertir el starter en un boilerplate corporativo listo para futuras features.
- Dejar la experiencia visible al usuario completamente en espanol.
- Eliminar del flujo inicial cualquier rastro de autoregistro publico.
- Hacer que el acceso principal del sistema sea login, no una landing page.
- Sustituir el branding generico por `Boilerplate Caracoders` y `Caracoders Pro Services`.
- Dejar un administrador inicial funcional para desarrollo local.
- Mantener una base coherente para futuras iteraciones sin romper el starter mas de lo necesario.

## Alcance (Scope)

### Incluido en esta iteracion

- Desactivar registro en backend y frontend.
- Hacer que `/register` responda `404`.
- Dejar login como raiz del proyecto.
- Desactivar la welcome page como interfaz de entrada y comentarla donde aplique.
- Traducir completamente al espanol:
    - auth
    - settings
    - dashboard inicial
    - sidebar
    - breadcrumbs
    - formularios
    - placeholders
    - validaciones
    - correos de reset/verificacion
    - 2FA
    - titulos de pagina
- Reemplazar branding Laravel/starter por branding de `Boilerplate Caracoders` y `Caracoders Pro Services`.
- Reemplazar logos e iconos usando como referencia el repo `boilerplate-laravel12`.
- Agregar footer corporativo simple.
- Quitar links de repositorio y documentacion del starter en el shell autenticado.
- Fijar `violet` como theme por defecto.
- Mantener el selector de apariencia light/dark.
- Desactivar y comentar `Delete account`.
- Seedear un usuario administrador local.

### Fuera de alcance en esta iteracion

- Modulo de usuarios futuro.
- Roles y permisos avanzados.
- Replanteo funcional del dashboard.
- Cambios grandes de arquitectura.
- Nuevas features de negocio.
- Observabilidad, logs o monitoreo.
- Reemplazo de 2FA por otro flujo; en esta iteracion solo se traduce.

## User Stories / Casos de Uso

- Como usuario autorizado, quiero ver login como pantalla inicial para acceder directamente al sistema.
- Como visitante no autorizado, no quiero tener acceso a registro publico ni a una welcome page que sugiera acceso abierto.
- Como usuario del sistema, quiero que todos los formularios, mensajes y validaciones esten en espanol.
- Como empresa, quiero que el sistema muestre identidad visual de `Caracoders Pro Services` en lugar del branding del starter.
- Como desarrollador local, quiero tener un administrador seeded para probar el sistema desde el primer arranque.
- Como usuario autenticado, quiero mantener la posibilidad de usar light/dark mode, pero con la identidad visual base de color `violet`.

## Requerimientos Funcionales

1. El sistema debe mostrar login como ruta raiz del proyecto.
2. La welcome page no debe usarse como interfaz publica principal.
3. La ruta `/register` debe responder `404`.
4. La funcionalidad de registro debe quedar desactivada tanto visual como funcionalmente.
5. Los textos visibles de autenticacion deben estar en espanol.
6. Los textos visibles de settings, sidebar, breadcrumbs y dashboard inicial deben estar en espanol.
7. Las validaciones backend deben mostrarse en espanol.
8. Los correos de reset password y verificacion deben estar en espanol.
9. El branding visible del sistema debe usar `Boilerplate Caracoders` como nombre principal.
10. La marca institucional secundaria debe ser `Caracoders Pro Services`.
11. Los logos del starter deben reemplazarse por una version basada en la referencia del repo `boilerplate-laravel12`.
12. Debe existir un footer corporativo simple.
13. Deben eliminarse del sidebar/header los links a repositorio y documentacion del starter.
14. El theme por defecto debe ser `violet`.
15. El selector de apariencia debe mantenerse operativo.
16. La funcionalidad `Delete account` debe quedar desactivada y no visible para el usuario final.
17. Debe existir un seeder que cree o actualice al admin local con:
    - nombre: `Administrador`
    - correo: `test@mailinator.com`
    - contrasena: `12345678`
18. Si el usuario admin ya existe, no debe duplicarse.
19. La funcionalidad 2FA debe mantenerse activa, pero traducida.

## Requerimientos No Funcionales

- Mantener compatibilidad con Laravel 13, Fortify, Inertia React y el starter actual.
- No eliminar agresivamente funcionalidades base del starter cuando baste con desactivarlas o comentarlas.
- Mantener la base preparada para futuras iteraciones del sistema.
- Usar convenciones del proyecto y respetar el `AGENTS.md` actual.
- Ejecutar validacion programatica del cambio con tests enfocados.
- Si se modifican archivos PHP, ejecutar `vendor/bin/pint --dirty --format agent`.
- Mantener seguridad razonable del baseline:
    - passwords hasheadas correctamente
    - no exponer registro publico
    - no introducir dependencias innecesarias
- Mantener la UI consistente con el sistema visual del proyecto.

## Criterios de Aceptacion

- `/` muestra o redirige a login segun el flujo final implementado.
- `/register` devuelve `404`.
- No existe acceso visual a registro en login ni en otras pantallas.
- La welcome page deja de ser parte del flujo principal.
- `Delete account` no aparece en la interfaz y no queda disponible para uso normal.
- Los textos visibles principales del sistema estan en espanol.
- Las validaciones relevantes se muestran en espanol.
- Los correos de reset y verificacion se generan en espanol.
- Los titulos de pagina estan en espanol y alineados con `Boilerplate Caracoders`.
- El dashboard inicial sigue siendo generico, pero ya no se siente como el starter publico.
- Sidebar, breadcrumbs y header usan textos en espanol.
- Los links del starter a repo/documentacion ya no aparecen.
- El branding visible corresponde a `Boilerplate Caracoders` / `Caracoders Pro Services`.
- El theme `violet` queda como default.
- El selector de apariencia sigue funcionando.
- El admin local seeded existe y no se duplica al repetir seeders.
- Los tests afectados quedan en verde.

## Dependencias y Riesgos

### Dependencias

- Laravel Fortify para auth y flujos secundarios.
- Inertia React para las pantallas del shell y auth.
- Traducciones Laravel / `lang/` o mecanismo equivalente para validaciones y correos.
- Seeder de base de datos para el admin local.
- Assets/logo tomados como referencia desde `boilerplate-laravel12`.
- MCPs activos del proyecto:
    - `laravel-boost`
    - `shadcn`

### Riesgos

- Traducir "todo" implica tocar frontend, backend y correos, no solo la UI React.
- Desactivar registro correctamente requiere cambios en config, rutas, UI y pruebas.
- Comentar `Delete account` sin revisar rutas/controladores puede dejar comportamiento residual.
- El reemplazo de branding puede tocar varias piezas distribuidas en layouts, componentes, assets y titulos.
- El logo del repo anterior puede requerir adaptacion para no arrastrar deuda visual o tecnica.
- El seeder del admin, si no se protege bien, puede comportarse distinto a lo esperado en otros entornos.

## Agrupacion recomendada de implementacion

1. `auth + routing`
2. `traduccion completa`
3. `branding + logos + footer`
4. `theme violet por defecto`
5. `seed admin`
6. `tests y verificacion final`

## Areas probablemente afectadas

- `config/fortify.php`
- `app/Providers/FortifyServiceProvider.php`
- `routes/web.php`
- `routes/settings.php`
- `resources/js/pages/auth/*`
- `resources/js/pages/settings/*`
- `resources/js/pages/welcome.tsx`
- `resources/js/components/app-header.tsx`
- `resources/js/components/app-sidebar.tsx`
- `resources/js/components/app-logo.tsx`
- `resources/js/components/app-logo-icon.tsx`
- `resources/css/app.css`
- `database/seeders/DatabaseSeeder.php`
- `lang/` y/o cadenas equivalentes del proyecto
- `tests/Feature/Auth/*`
- `tests/Feature/Settings/*`
- assets publicos del branding

## Nota de tooling

- El proyecto ya cuenta con `Laravel Boost` como MCP Laravel-aware.
- El proyecto ahora tambien cuenta con MCP de `shadcn` para consulta e instalacion de componentes UI durante futuras iteraciones visuales.
