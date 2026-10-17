# Excepciones operativas

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores que agregan manejo de errores, auditoría o logging operativo
    - Fuente verificable: `app/Exceptions/BoilerplateException.php`, `docs/adr/ADR-007-logging-and-correlation.md`, `docs/adr/ADR-005-audit-boundary.md`, exception handler de Laravel

Esta guía define cuándo lanzar excepciones, cuándo registrar logs y cuándo auditar eventos.

## Cuándo lanzar, registrar o auditar

| Situación | Acción |
| --- | --- |
| Violación esperada de regla de negocio, como envío duplicado | Lanza una subclase de `BoilerplateException` |
| Falla técnica inesperada, como timeout de API externa | `Log::warning()` y lanza excepción si no es recuperable |
| Evento de seguridad, como login, logout, cambio de rol o 2FA | Llama a `SecurityAuditService::record()` |
| Cambio de campo de un modelo auditable | Lo maneja `laravel-auditing` mediante Eloquent |
| Error de sistema no recuperable | `Log::error()` mediante el exception handler |

## Prohibiciones

| Prohibido | Alternativa correcta |
| --- | --- |
| `abort(422, 'Business error')` para reglas de negocio | `throw new MyException(...)` con subclase de `BoilerplateException` |
| `env()` fuera de archivos de configuración | `config('key')` |
| `Log::shareContext()` para correlación | `Context::add()` |
| SQL crudo en modelos auditables | Métodos de Eloquent |
| Escritura directa en `security_audit_log` | `SecurityAuditService::record()` |
| Paths de archivos hardcodeados | `Storage::disk('name')->put(...)` |
| Jobs sin `$tries`, `$backoff` y `$timeout` | Política explícita de retry en cada job |
| Importar `Spatie\Permission\Models\Role` | Importar `App\Models\Role` |

## Niveles de log

- Usa `Log::warning()` para fallas técnicas esperadas o recuperables que deben investigarse.
- Reserva `Log::error()` para fallas no recuperables o propagadas al handler.
- No uses `Log::emergency()` para errores de negocio; queda reservado para alertas de sistema.

## Ver también

- [Logging y correlación](logging-correlation.md)
- [Auditoría](auditing.md)
- [Storage](storage.md)
