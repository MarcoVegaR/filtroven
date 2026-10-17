---
title: Copiloto de usuarios
summary: Guía completa del asistente inteligente que te permite consultar, analizar y actuar sobre cuentas de usuario desde un panel conversacional.
category: Usuarios
order: 60
---

El **Copiloto de usuarios** es un asistente integrado que te permite consultar información, analizar accesos y proponer acciones sobre cuentas de usuario, todo desde un panel conversacional sin salir de la pantalla.

## 🤖 ¿Qué es el Copiloto?

Es un panel lateral que se abre como una ventana de chat. Puedes escribir preguntas en **lenguaje natural** (como si hablaras con un compañero) y el Copiloto consulta los datos del sistema para responderte con tarjetas visuales, métricas y propuestas de acción.

> 💡 **Dato clave:** El Copiloto **nunca ejecuta acciones por su cuenta**. Siempre te muestra una propuesta con un botón de confirmación y espera tu aprobación antes de hacer cualquier cambio.

## 🚀 ¿Cómo lo abro?

Hay **dos maneras** de usar el Copiloto, dependiendo de si quieres consultar sobre un usuario en particular o hacer preguntas generales:

### Desde el perfil de un usuario (modo contextual)

1. Ve al [listado de usuarios](/system/users) y haz clic en el nombre de la persona.
2. En la barra de acciones del perfil, haz clic en el botón **Copiloto** (con el ícono ✨).
3. Se abrirá el panel lateral con sugerencias adaptadas a esa persona.

> 💡 En este modo, el Copiloto ya sabe de quién estás hablando. Puedes preguntar directamente "¿Qué roles tiene?" sin necesidad de indicar el nombre.

### Sin contexto de usuario (modo general)

Si abres el Copiloto sin estar en un perfil específico, puedes hacer preguntas generales como buscar usuarios, consultar métricas o pedir resúmenes.

## 📋 ¿Qué puedo preguntarle?

El Copiloto entiende diferentes tipos de consultas. Aquí tienes ejemplos concretos que puedes copiar y pegar:

### Consultar información

| Pregunta                                          | ¿Qué hace?                                                                    |
| ------------------------------------------------- | ----------------------------------------------------------------------------- |
| "Resume el estado actual de María"                | Muestra una tarjeta con estado, roles, permisos, 2FA y verificación de correo |
| "¿Qué roles tiene juan@empresa.com?"              | Lista los roles asignados y su estado (activo/inactivo)                       |
| "¿Qué puede hacer este usuario?"                  | Muestra los permisos efectivos agrupados por módulo                           |
| "¿Tiene habilitada la verificación en dos pasos?" | Indica si 2FA está configurado y confirmado                                   |

### Buscar usuarios

| Pregunta                        | ¿Qué hace?                                            |
| ------------------------------- | ----------------------------------------------------- |
| "Busca usuarios inactivos"      | Muestra una lista de usuarios con estado inactivo     |
| "¿Quiénes son administradores?" | Busca usuarios con rol de administrador               |
| "¿Quién puede crear roles?"     | Identifica usuarios que tienen ese permiso específico |

### Consultar métricas

| Pregunta                          | ¿Qué hace?                                                   |
| --------------------------------- | ------------------------------------------------------------ |
| "¿Cuántos usuarios hay en total?" | Muestra el total con desglose por estado (activos/inactivos) |
| "¿Cuáles roles existen?"          | Lista todos los roles del sistema                            |

### Proponer acciones

| Pregunta                                                       | ¿Qué hace?                                            |
| -------------------------------------------------------------- | ----------------------------------------------------- |
| "Desactiva esta cuenta"                                        | Propone la desactivación y espera tu confirmación     |
| "Activa esta cuenta"                                           | Propone la reactivación de una cuenta inactiva        |
| "Envía un correo de restablecimiento de contraseña"            | Propone enviar el email de reset                      |
| "Crea un usuario nuevo llamado Ana con correo ana@empresa.com" | Propone un alta guiada con los datos que proporcionas |

## ✅ ¿Cómo funcionan las acciones?

Cuando el Copiloto propone una acción, verás una **tarjeta de acción** con:

1. **Tipo de acción** — Qué se va a hacer (activar, desactivar, enviar reset, crear usuario).
2. **Resumen** — Descripción clara de lo que pasará.
3. **Usuario afectado** — Nombre y correo de la persona sobre la que se ejecutará.
4. **Estado** — Si dice "Lista para confirmar" puedes ejecutarla. Si dice "Solo propuesta" no tienes los permisos necesarios.
5. **Botón Confirmar acción** — Solo se activa si tienes permiso.

Al hacer clic en **Confirmar acción**, aparece un **diálogo de confirmación** donde debes aceptar explícitamente. Nada se ejecuta hasta que confirmes en ese diálogo.

> ⚠️ **Las acciones destructivas** (como desactivar una cuenta) se muestran con un botón rojo para que sea evidente el impacto.

### Alta guiada de usuario

Si pides crear un usuario nuevo, el Copiloto:

1. Te propone la acción con los datos que proporcionaste (nombre, correo, roles).
2. Al confirmar, crea la cuenta y te muestra una **tarjeta de credenciales temporales** con:
    - Nombre y correo del nuevo usuario.
    - Una **contraseña temporal** que debes copiar y compartir por un canal seguro.
    - Un botón para **copiar la contraseña** al portapapeles.
    - Un botón para **ocultar la tarjeta** (la contraseña solo se muestra una vez).

> ⚠️ **La contraseña temporal solo se muestra una vez.** Si cierras la tarjeta sin copiarla, necesitarás enviar un correo de restablecimiento desde el perfil del usuario.

## 💬 Sugerencias de inicio

Cuando abres el Copiloto por primera vez, verás **tarjetas con sugerencias** que puedes hacer clic para enviarlas directamente. Estas sugerencias cambian según:

- **Si estás en el perfil de un usuario:** "Resume su estado actual", "¿Qué roles y permisos tiene?", "¿Qué puede hacer?", "Propón desactivar".
- **Si no estás en un perfil:** "¿Cuántos usuarios hay?", "Buscar usuarios inactivos", "¿Quiénes son admin?", "¿Quién puede crear roles?".

También puedes escribir tu propia pregunta en el campo de texto. Usa **Ctrl + Enter** (o **Cmd + Enter** en Mac) para enviar rápidamente.

## 🔒 Permisos necesarios

El Copiloto tiene **dos niveles de acceso** que controlan lo que puedes hacer:

| Permiso                                                | ¿Qué te permite?                                                                 |
| ------------------------------------------------------ | -------------------------------------------------------------------------------- |
| **Ver copiloto** (`system.users-copilot.view`)         | Abrir el panel y hacer consultas de solo lectura (estados, métricas, búsquedas)  |
| **Ejecutar acciones** (`system.users-copilot.execute`) | Confirmar acciones propuestas (activar, desactivar, crear usuario, enviar reset) |

Además, cada acción requiere sus **permisos funcionales propios**:

| Acción                                | Permisos necesarios además del copiloto  |
| ------------------------------------- | ---------------------------------------- |
| Activar / Desactivar usuario          | Permiso de desactivación de usuarios     |
| Enviar restablecimiento de contraseña | Permiso de envío de reset                |
| Alta guiada (crear usuario)           | Permiso de crear usuario + asignar roles |

> 💡 Si el Copiloto te muestra una propuesta pero el botón está deshabilitado con la etiqueta "Solo propuesta", significa que te falta uno de estos permisos. Contacta a un administrador.

### ¿No ves el botón del Copiloto?

Si no aparece el botón **Copiloto** en el perfil de un usuario, puede deberse a:

- No tienes el permiso `system.users-copilot.view`.
- El módulo de copiloto está desactivado por configuración del sistema.
- El canal web del copiloto no está habilitado.

Contacta a un administrador para que revise tu acceso.

## 🧠 Conversación con memoria

El Copiloto **recuerda el contexto** dentro de la misma sesión. Esto significa que puedes encadenar preguntas sin repetir información:

1. "¿Qué roles tiene María?"
2. "¿Puede crear usuarios?" _(el Copiloto sabe que hablas de María)_
3. "Propón desactivarla" _(sigue en contexto)_

> ⚠️ La conversación se reinicia al cerrar el panel. Si vuelves a abrirlo, el Copiloto empieza desde cero.

## 🃏 Tipos de respuesta visual

El Copiloto responde con diferentes tipos de **tarjetas visuales** según lo que preguntes:

| Tarjeta                       | ¿Cuándo aparece?               | ¿Qué muestra?                                                     |
| ----------------------------- | ------------------------------ | ----------------------------------------------------------------- |
| **Contexto de usuario** 👤    | Al pedir un resumen de alguien | Estado, correo, 2FA, roles y permisos efectivos                   |
| **Resultados de búsqueda** 🔍 | Al buscar usuarios             | Lista de usuarios con estado, roles y enlace a su perfil          |
| **Métricas** 📊               | Al preguntar "¿cuántos...?"    | Valor principal con desglose (activos, inactivos, etc.)           |
| **Propuesta de acción** ⚡    | Al pedir una acción            | Tipo de acción, resumen, usuario afectado y botón de confirmación |
| **Aclaración** ❓             | Si la pregunta es ambigua      | Te pide más detalles con opciones sugeridas                       |
| **Aviso** ℹ️                  | Información complementaria     | Roles, permisos u otros datos relevantes                          |
| **Resultado de acción** ✅    | Tras confirmar una acción      | Confirmación de que la acción se ejecutó correctamente            |
| **Credenciales** 🔑           | Tras crear un usuario          | Contraseña temporal para copiar y compartir                       |

## ⚠️ Limitaciones

- El Copiloto solo trabaja con el **módulo de usuarios** — no puede gestionar otros módulos del sistema.
- Tiene un **límite de consultas por minuto** para evitar sobrecargas. Si ves un error de límite, espera unos segundos.
- No puede **eliminar** usuarios — para eso usa la [gestión manual](/help/users/manage-users).
- Si la pregunta está fuera de su alcance, te lo indicará amablemente.

## 📖 Artículos relacionados

- [Gestionar usuarios](/help/users/manage-users) — Para encontrar al usuario sobre el que quieres consultar.
- [Crear un usuario](/help/users/create-user) — El proceso manual de alta, complementario al alta guiada del Copiloto.
- [Asignar roles a un usuario](/help/users/assign-roles) — Para entender los roles que el Copiloto puede mostrar o asignar.
- [Desactivar un usuario](/help/users/deactivate-user) — El proceso manual de desactivación.
- [Estados y ciclo de vida](/help/users/user-lifecycle) — Para entender los estados que el Copiloto puede mencionar.
- [Consultar eventos de auditoría](/help/audit/review-audit-events) — Las acciones del Copiloto quedan registradas aquí.
