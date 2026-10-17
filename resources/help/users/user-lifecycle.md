---
title: Estados y ciclo de vida de un usuario
summary: Entiende los estados posibles de una cuenta (activo, inactivo, etc.) y qué puedes hacer en cada uno.
category: Usuarios
order: 50
---

Cada cuenta de usuario pasa por distintos estados a lo largo del tiempo. Entender estos estados te ayuda a saber por qué alguien puede o no puede entrar al sistema.

## 🚦 Estados posibles

| Estado                    | ¿Qué significa?                                                                 | ¿Puede entrar? |
| ------------------------- | ------------------------------------------------------------------------------- | -------------- |
| **Activo** ✅             | La cuenta está habilitada y lista para trabajar                                 | Sí             |
| **Inactivo** 🔒           | Un administrador suspendió la cuenta                                            | No             |
| **Sin verificar** 📧      | La persona aún no confirmó su correo electrónico                                | No             |
| **Sin segundo factor** 🔐 | El sistema exige verificación en dos pasos, pero la persona aún no la configuró | No             |

## 🔄 ¿Cómo cambia el estado de una cuenta?

El camino típico de una cuenta se ve así:

**Creación** → **Sin verificar** → **Activo** → **Inactivo** → **Activo** (reactivación)

- Cuando se crea una cuenta, puede quedar como "sin verificar" si el sistema exige confirmar el correo.
- Un administrador puede **desactivar** una cuenta activa en cualquier momento.
- Una cuenta inactiva puede **reactivarse** sin perder datos ni historial.
- Si el sistema exige verificación en dos pasos, la cuenta queda bloqueada hasta que la persona la configure.

## 📋 ¿Qué puedes hacer en cada estado?

| Acción                              | Activo ✅ | Inactivo 🔒 |
| ----------------------------------- | --------- | ----------- |
| Editar datos                        | ✅ Sí     | ✅ Sí       |
| Asignar roles                       | ✅ Sí     | ✅ Sí       |
| Desactivar                          | ✅ Sí     | —           |
| Reactivar                           | —         | ✅ Sí       |
| Enviar enlace para nueva contraseña | ✅ Sí     | ✅ Sí       |
| Ver historial de auditoría          | ✅ Sí     | ✅ Sí       |

> 💡 **Consejo:** Puedes editar los datos y roles de una cuenta inactiva. Esto es útil para preparar todo antes de reactivarla.

## ⚠️ Antes de cambiar el estado de una cuenta

Revisa en la [auditoría](/help/audit/review-audit-events) si la persona tiene actividad reciente. Desactivar una cuenta en medio de una operación puede causar problemas.

## 📖 Artículos relacionados

- [Desactivar un usuario](/help/users/deactivate-user) — Paso a paso para suspender el acceso.
- [Gestionar usuarios](/help/users/manage-users) — Para trabajar con el listado completo de cuentas.
