---
title: Asignar permisos a un rol
summary: Cómo elegir los permisos correctos para un rol sin dar acceso de más ni de menos.
category: Roles y permisos
order: 30
---

Los **permisos** son las acciones concretas que alguien puede hacer en el sistema: ver datos, crear registros, editar, eliminar, etc. Cada rol agrupa un conjunto de permisos.

## 🧩 ¿Cómo están organizados los permisos?

Los permisos se agrupan por **módulo** (la sección del sistema) y por **tipo de acción**. Por ejemplo:

| Permiso                     | ¿Qué permite?                          |
| --------------------------- | -------------------------------------- |
| `sistema.usuarios.ver`      | Ver el listado de usuarios y sus datos |
| `sistema.usuarios.crear`    | Crear cuentas nuevas                   |
| `sistema.usuarios.editar`   | Modificar datos de cuentas existentes  |
| `sistema.usuarios.eliminar` | Borrar cuentas                         |

Este patrón se repite en cada módulo del sistema (roles, auditoría, etc.), con nombres similares.

## 🚀 Paso a paso

1. Abre el rol que quieres modificar desde el [listado de roles](/help/roles-and-permissions/manage-roles), o [crea uno nuevo](/help/roles-and-permissions/create-role).
2. Busca el **selector de permisos**, que aparece agrupado por módulo.
3. Marca **solo** los permisos que el rol necesita.
4. Haz clic en **Guardar**.
5. [Asigna el rol a un usuario](/help/users/assign-roles) de prueba y verifica que los permisos funcionan como esperas.

> ⚠️ **Regla de oro:** Si dudas entre incluir un permiso o no, **no lo incluyas**. Es mucho más fácil agregar un permiso después que investigar por qué alguien tuvo acceso a algo que no debía.

## ✅ ¿Cómo verificar que todo está bien?

Después de guardar, comprueba estos tres puntos:

1. **La persona puede hacer lo que necesita** — Pídele que pruebe las acciones principales.
2. **La persona NO puede hacer lo que no debería** — Verifica que no tenga acceso extra.
3. **El cambio quedó registrado** — Revisa en la [auditoría](/help/audit/review-audit-events) que el cambio de permisos aparece.

## 📖 Artículos relacionados

- [Crear un rol](/help/roles-and-permissions/create-role) — Si necesitas un rol nuevo antes de asignar permisos.
- [Revisar mi acceso](/help/security-access/review-my-access) — Para que la persona verifique sus permisos desde su propio perfil.
