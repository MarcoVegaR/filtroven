# Referencia

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `referencia`
    - Audiencia: mantenedores y revisores que necesitan fuentes verificables
    - Fuente verificable: comandos Artisan, seeders, policies, middleware, tests y configuración

Este índice reúne fuentes verificables para revisar el estado real del proyecto sin crear catálogos manuales completos que se vuelven obsoletos.

<div class="grid cards" markdown>

-   :material-shield-key-outline: **Autorización**

    Permisos, políticas y límites de acceso.

    [Leer referencia](../authorization.md)

-   :material-terminal: **Comandos Artisan**

    Lista comandos disponibles y revisa ayuda específica desde el runtime.

    `php artisan list`

-   :material-routes: **Rutas**

    Consulta rutas registradas directamente desde Laravel.

    `php artisan route:list`

-   :material-cog-outline: **Configuración**

    Verifica valores efectivos desde `config/` y comandos de configuración.

    `php artisan config:show`

</div>

## Fuentes verificables

| Necesidad | Fuente primaria | Verificación recomendada |
| --- | --- | --- |
| Rutas publicadas | `routes/` y service providers | `php artisan route:list` |
| Comandos disponibles | comandos registrados por Laravel y la app | `php artisan list` y `php artisan help <comando>` |
| Configuración efectiva | `config/`, `.env.example` y runtime | `php artisan config:show` |
| Permisos y roles | seeders, policies, middleware y tests | seeders de permisos + pruebas de autorización |
| Jobs, listeners y eventos | `app/Jobs`, `app/Listeners`, `app/Events` | código versionado + tests relevantes |
| Assets frontend | `package.json`, Vite y build output | `npm run build` |

## Regla editorial

- No dupliques listados completos de rutas, comandos, variables o permisos si se pueden derivar por comando o inspección de archivos versionados.
- Si una referencia necesita contexto humano, enlaza la guía curada y deja la fuente verificable explícita.
- Si una fuente verificable cambia, actualiza primero el código, tests o configuración; la documentación debe explicar el criterio, no reemplazar la fuente de verdad.
