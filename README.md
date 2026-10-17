# Filtroven

Aplicación Laravel 13 con Inertia v2, React 19, TypeScript, Tailwind CSS v4 y Vite 8. Esta importación conserva las capacidades técnicas del origen; los requisitos de negocio de Filtroven se definirán por separado.

## Procedencia y licencia

- Origen: [MarcoVegaR/boilerplate-laravel13](https://github.com/MarcoVegaR/boilerplate-laravel13).
- Snapshot fijo: [`dfa975369939913890b7969281d775261c3e5598`](https://github.com/MarcoVegaR/boilerplate-laravel13/commit/dfa975369939913890b7969281d775261c3e5598), obtenido de `main` e importado sin historial Git.
- Destino: [MarcoVegaR/filtroven](https://github.com/MarcoVegaR/filtroven).
- Se conserva [LICENSE](LICENSE): MIT, copyright 2026 Marco Vega.

> **Contexto histórico:** los `PRD*.md`, ADRs, reportes y guías importados documentan el proyecto de origen. Sus estados de aceptación o vigencia no los convierten en requisitos ni decisiones ratificadas de Filtroven. Este README es el punto de entrada actual; las funciones de negocio requieren alcance explícito.

## Requisitos

- PHP 8.4 o superior (entorno de importación: PHP 8.5) y Composer 2. Comprueba las extensiones requeridas con `composer check-platform-reqs`; para el entorno local se necesitan `pdo_pgsql` y `redis`, y para las pruebas aisladas `pdo_sqlite`.
- Node.js 22.12+ y npm (Vite 8 requiere una versión moderna de Node 22).
- PostgreSQL con una base **exclusiva** `filtroven`. Las migraciones utilizan `unaccent` y `pg_trgm`; el rol debe poder habilitarlas o un administrador debe prepararlas en esa base.
- Redis para sesiones, caché y colas; Mailpit para SMTP local.
- MinIO si se conserva el disco S3 predeterminado, con un bucket propio `filtroven-local`. Para trabajar solo con archivos locales se puede elegir `FILESYSTEM_DISK=local` en `.env`.

No se incluye Docker Compose ni se aprovisionan servicios automáticamente. Las variables `AWS_*` configuran el adaptador S3-compatible local; no implican usar un proveedor cloud.

## Instalación local

Desde la raíz del repositorio:

```bash
cp .env.example .env
composer install --no-interaction --prefer-dist
npm ci
php artisan key:generate --no-interaction
```

Revisa `.env` antes de conectar servicios. Los valores de desarrollo son ejemplos, no credenciales de producción:

| Servicio | Configuración de ejemplo |
| --- | --- |
| Aplicación | `APP_NAME=Filtroven`; `APP_URL=http://localhost:8000` |
| PostgreSQL | `127.0.0.1:5432`, base `filtroven`; ajustar usuario y contraseña locales |
| Redis | `127.0.0.1:6379`, DB 0 y caché DB 1 |
| Aislamiento | `REDIS_PREFIX=filtroven-local-database-`, `CACHE_PREFIX=filtroven-local-cache-`, `SESSION_COOKIE=filtroven-local-session` |
| Correo | Mailpit SMTP en `127.0.0.1:1025`; remitente de prueba `hello@filtroven.test` |
| Archivos | MinIO en `127.0.0.1:9000`, bucket `filtroven-local`; ajustar credenciales al servicio existente |

El remitente `.test` no es un dominio público de Filtroven. Usa prefijos distintos para cada aplicación/entorno que comparta Redis; no ejecutes `FLUSHDB` en instancias compartidas. `.env` está excluido de Git; nunca publiques claves o credenciales reales.

**Solo después de confirmar la base exclusiva y sus credenciales/extensiones**, ejecuta manualmente:

```bash
php artisan migrate --no-interaction
```

No se ejecutan seeders automáticamente ni se presupone una cuenta inicial. El registro público heredado está deshabilitado; revisa los seeders y acuerda el mecanismo de acceso antes de poblar datos. No uses `composer run setup` para esta instalación: el script heredado ejecuta migraciones con `--force` y `npm install`.

Para iniciar el desarrollo, con los servicios ya disponibles:

```bash
composer run dev
```

El script inicia servidor PHP, listener de colas, logs y Vite. Para ejecutar procesos por separado están disponibles `php artisan serve`, `npm run dev`, `composer run local:queue` y `composer run local:schedule`. No dupliques workers si ya usas `composer run dev`.

## Verificación

```bash
composer validate --strict --no-check-publish
composer check-platform-reqs
npm run build
npm run types:check
npm run lint:check
npm run format:check
php artisan test --compact
```

`npm run build` y `types:check` generan rutas Wayfinder mediante Artisan: requieren `vendor/` y una aplicación PHP que pueda arrancar. Los archivos generados y `public/build` están excluidos de Git.

Por defecto `phpunit.xml` usa SQLite **en memoria**, caché/sesiones en array, correo en array y colas síncronas. Evita variables de shell que sobrescriban esa configuración cuando ejecutes pruebas locales. Los tests pueden crear fixtures/seeders dentro de la base de prueba aislada; no son una carga inicial de datos locales. CI verifica además PostgreSQL en una base efímera `filtroven_test` para PHP 8.4 y 8.5.

## Documentación y automatización

Para revisar el portal técnico sin publicar:

Se necesita Python 3 con soporte `venv` y `pip` (en Debian/Ubuntu, paquete `python3-venv`).

```bash
python3 -m venv /tmp/filtroven-docs-venv
/tmp/filtroven-docs-venv/bin/python -m pip install -r requirements.txt
/tmp/filtroven-docs-venv/bin/mkdocs build --strict
```

- `.github/workflows/ci.yml`: calidad, compilación y pruebas en `main` y pull requests.
- `.github/workflows/docs.yml`: validación estricta de MkDocs, sin publicación en Pages.
- `.github/workflows/deploy.yml`: aviso manual de despliegue desactivado; sin SSH, secretos ni destino heredado. Definir infraestructura propia antes de habilitar despliegues.
- Dependabot conserva revisiones de dependencias mediante pull requests. Esta importación mantiene las versiones fijadas en ambos lockfiles; solo se sincronizan el `content-hash` de Composer y los nombres del proyecto raíz en el lock de npm.

No hay dominio público, logotipo nuevo ni destino de producción configurados. Las reglas operativas del proyecto están en [AGENTS.md](AGENTS.md).
