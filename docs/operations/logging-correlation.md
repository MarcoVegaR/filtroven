# Logging y correlación

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores que agregan logging, jobs o trazabilidad operativa
    - Fuente verificable: `app/Http/Middleware/HandleCorrelation.php`, `bootstrap/app.php`, `config/logging.php`, `docs/adr/ADR-007-logging-and-correlation.md`

El proyecto usa un `correlation_id` por solicitud para agrupar logs, respuestas HTTP y trabajos en cola derivados del mismo flujo.

## Mecanismo de correlación

- `App\Http\Middleware\HandleCorrelation` asigna un UUID v4 a cada solicitud HTTP.
- Si la solicitud trae un `X-Correlation-ID` válido, se reutiliza.
- El valor se guarda con `Context::add('correlation_id', $value)`.
- La respuesta devuelve el mismo valor en el header `X-Correlation-ID`.

## `Context` es la vía canónica

Usa `Illuminate\Support\Facades\Context` para el contexto compartido.

| Prohibido | Alternativa correcta |
| --- | --- |
| `Log::shareContext()` para correlación | `Context::add()` |
| `Log::withContext()` como mecanismo principal de correlación | `Context::add()` en middleware o al iniciar el trabajo |

`Context` propaga automáticamente datos a jobs despachados desde una solicitud HTTP. `Log::shareContext()` no cubre ese ciclo de vida.

## Canales de log

| Canal | Propósito | Fuente verificable |
| --- | --- | --- |
| `stack` | Canal por defecto | `config/logging.php` |
| `daily` | Logs generales rotados diariamente | `config/logging.php` |
| `security` | Eventos de seguridad aislados | `config/logging.php`, `SecurityAuditService` |

Los eventos de seguridad no deben registrarse con `Log::info()` plano. Usa el flujo de [Auditoría](auditing.md) cuando el evento represente autenticación, autorización o cruce de frontera de confianza.

## Seguridad y formato

- No leas `env('LOG_*')` fuera de archivos de configuración; usa `config('logging.*')`.
- No uses `Log::emergency()` para errores de negocio.
- La opción comentada `LOG_FORMAT=json` en `.env.example` requiere configuración adicional de formatter antes de activarse.

## Jobs sin contexto HTTP

Los jobs despachados desde scheduler o comandos Artisan no tienen un `correlation_id` previo. Si necesitan trazabilidad, deben establecerlo al inicio de `handle()`:

```php
Context::add('correlation_id', (string) Str::uuid());
```

## Ver también

- [Colas y scheduler](queues-scheduler.md)
- [Excepciones operativas](exceptions.md)
- [ADR-007: Logging and Correlation Policy](../adr/ADR-007-logging-and-correlation.md)
