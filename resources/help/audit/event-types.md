---
title: Tipos de eventos y fuentes de auditoría
summary: Referencia para entender los diferentes tipos de eventos que registra el sistema y cómo diferenciarlos.
category: Auditoría
order: 20
---

El sistema registra automáticamente dos tipos de actividad: **cambios en datos** y **eventos de seguridad**. Ambos aparecen en la misma vista de auditoría, pero puedes filtrarlos por separado.

## 📂 ¿Qué fuentes de eventos existen?

| Fuente           | ¿Qué registra?                      | Ejemplo                                         |
| ---------------- | ----------------------------------- | ----------------------------------------------- |
| **Modelos** 📝   | Cambios en los datos del sistema    | Se creó un usuario, se editó un rol             |
| **Seguridad** 🔐 | Actividad relacionada con el acceso | Alguien inició sesión, se cambió una contraseña |

## 📝 Eventos de cambios en datos (Modelos)

| Evento            | ¿Cuándo ocurre?                                      |
| ----------------- | ---------------------------------------------------- |
| **Creación**      | Se creó un registro nuevo (un usuario, un rol, etc.) |
| **Actualización** | Se modificaron datos de un registro existente        |
| **Eliminación**   | Se borró un registro                                 |

## 🔐 Eventos de seguridad

| Evento                  | ¿Cuándo ocurre?                                                      |
| ----------------------- | -------------------------------------------------------------------- |
| **Inicio de sesión** ✅ | Alguien entró al sistema correctamente                               |
| **Intento fallido** ❌  | Se intentó entrar con datos incorrectos o la cuenta estaba bloqueada |
| **Cierre de sesión**    | Alguien salió del sistema                                            |
| **2FA configurado**     | Se activó la verificación en dos pasos                               |
| **Contraseña cambiada** | Se actualizó la contraseña de una cuenta                             |

## 🕵️ ¿Cuándo usar cada fuente?

- **Modelos** → Cuando necesitas saber **quién cambió un dato** y qué valores tenía antes y después.
- **Seguridad** → Cuando necesitas rastrear **intentos de acceso**, horarios de sesión o cambios de contraseña.
- **Todas** → Cuando investigas una situación que involucra **datos y acceso** al mismo tiempo.

## 📋 ¿Qué información muestra el detalle de un evento?

Al hacer clic en cualquier evento, verás:

- **Actor** — Quién realizó la acción.
- **Entidad** — Sobre qué registro se actuó (el usuario, el rol, etc.).
- **Valores anteriores y nuevos** — Solo para cambios en datos; muestra qué había antes y qué quedó después.
- **Información técnica** — Para eventos de seguridad: dirección IP, navegador, etc. (cuando el sistema los registre).

## 📖 Artículos relacionados

- [Consultar eventos de auditoría](/help/audit/review-audit-events) — Cómo buscar y filtrar eventos paso a paso.
