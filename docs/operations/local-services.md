# Servicios locales

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores y mantenedores que ejecutan el entorno local
    - Fuente verificable: `.env.example`, `config/filesystems.php`, `config/logging.php` y servicios locales disponibles

Esta guía resume los servicios externos que el entorno local espera para operar con la configuración versionada.

## Redis

Redis es el servicio local para sesiones, cache y colas:

| Uso | Variable verificable |
| --- | --- |
| Sesiones | `SESSION_DRIVER=redis` |
| Cache | `CACHE_STORE=redis` |
| Colas | `QUEUE_CONNECTION=redis` |
| Host local | `REDIS_HOST=127.0.0.1` |

Los nombres de cola verificables viven en `.env.example`: `REDIS_QUEUE_CONNECTION=default` y `REDIS_QUEUE=default`.

## Mailpit

El correo local usa SMTP contra Mailpit:

| Uso | Variable verificable |
| --- | --- |
| Mailer | `MAIL_MAILER=smtp` |
| Host desde el host | `MAIL_HOST=127.0.0.1` |
| Puerto SMTP | `MAIL_PORT=1025` |

No documentes credenciales reales en esta sección. Los valores locales verificables están en `.env.example`.

## MinIO

El almacenamiento S3-compatible local usa MinIO mediante las variables `MINIO_*` y `AWS_*` de `.env.example`.

| Acceso | Endpoint verificable |
| --- | --- |
| API desde el host | `http://127.0.0.1:9000` |
| Consola desde el host | `http://127.0.0.1:9001` |
| Endpoint desde contenedores Docker | `AWS_ENDPOINT=http://minio:9000` |

En Docker, `http://minio:9000` es el hostname del servicio interno. Desde el navegador del host o herramientas ejecutadas fuera de Docker, usa los puertos publicados por el entorno local.

## Regla host vs Docker

- Usa `127.0.0.1` cuando la herramienta corre en el host.
- Usa el nombre del servicio Docker cuando el proceso corre dentro de la red de contenedores.
- No cambies código para alternar entre MinIO y AWS S3; ajusta variables de entorno y configuración.

## Ver también

- [Storage](storage.md)
- [Colas y scheduler](queues-scheduler.md)
