---
title: Asignar roles a un usuario
summary: Cómo agregar o cambiar los roles de una persona para controlar a qué puede acceder en el sistema.
category: Usuarios
order: 40
---

Los **roles** son como etiquetas que le dicen al sistema qué puede hacer cada persona. Por ejemplo, un rol de "Editor" podría dar permiso para modificar datos, mientras que uno de "Consultor" solo permite ver información.

## 📋 Antes de asignar un rol

- Revisa qué roles existen en el [listado de roles](/help/roles-and-permissions/manage-roles) y cuáles están activos.
- Confirma que el rol incluye los permisos que la persona necesita.
- Si existe un rol específico para el caso, **prefiere ese** antes que uno genérico con muchos permisos.

## 🚀 Paso a paso

1. Ve al **listado de usuarios** y busca a la persona.
2. Haz clic en **Editar** (desde las acciones de la fila o desde el perfil del usuario).
3. En la sección de roles, marca los que correspondan.
4. Haz clic en **Guardar**.
5. Pide a la persona que revise en [Configuración > Acceso](/settings/access) si sus permisos son los correctos.

## ✅ ¿Cómo saber si funcionó?

| Señal                                           | Significado                         |
| ----------------------------------------------- | ----------------------------------- |
| El rol aparece en el perfil del usuario ✅      | Se guardó correctamente             |
| Los permisos que necesita están en su acceso ✅ | El rol tiene los permisos correctos |
| No aparecen permisos que no debería tener ✅    | No se dio acceso de más             |

> ⚠️ **Importante:** Si asignas un rol que no está activo, no tendrá ningún efecto. Verifica siempre que el rol esté en estado activo.

## 💡 Buenas prácticas

- Asigna la **menor cantidad de roles** posible que cubra lo que la persona necesita.
- Si necesitas un permiso puntual que no está en ningún rol existente, evalúa si conviene [crear un nuevo rol](/help/roles-and-permissions/create-role) o [ajustar los permisos](/help/roles-and-permissions/assign-permissions) de uno existente.
- Documenta internamente para qué sirve cada rol — así todo el equipo asigna de forma consistente.

## 📖 Artículos relacionados

- [Crear un rol](/help/roles-and-permissions/create-role) — Si no existe un rol adecuado.
- [Asignar permisos a un rol](/help/roles-and-permissions/assign-permissions) — Para ajustar lo que un rol permite hacer.
- [Revisar mi acceso](/help/security-access/review-my-access) — Para que la persona verifique sus propios permisos.
