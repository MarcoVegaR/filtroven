# Auditoría

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores que agregan modelos auditables o eventos de seguridad
    - Fuente verificable: `config/audit.php`, `App\Services\SecurityAuditService`, `App\Enums\SecurityEventType`, `docs/adr/ADR-005-audit-boundary.md`

El proyecto separa auditoría de cambios de modelo y eventos de seguridad. No mezcles ambas rutas.

## Modelos auditables

Para agregar auditoría a un modelo Eloquent, implementa `Auditable` y usa el trait de `owen-it/laravel-auditing`:

```php
use OwenIt\Auditing\Contracts\Auditable;

class Article extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $auditExclude = [
        // Campos sensibles específicos del modelo.
    ];
}
```

Reglas obligatorias:

- Excluye campos sensibles con `$auditExclude` en el modelo.
- No dependas solo de `config/audit.php`; la exclusión debe aplicar en ambas capas cuando corresponda.
- No uses `DB::table()` ni SQL crudo para mutar modelos auditables, porque se saltan los observers de Eloquent.
- Si una prueba necesita auditoría activa, habilita `audit.console` y registra el observer explícitamente.

```php
config(['audit.console' => true]);
ModelName::observe(\OwenIt\Auditing\AuditableObserver::class);
```

## Modelos de Spatie extendidos localmente

El boilerplate provee `App\Models\Role` y `App\Models\Permission` como subclases locales. Importa siempre desde `App\Models\*`:

```php
use App\Models\Permission;
use App\Models\Role;
```

No importes `Spatie\Permission\Models\Role` ni `Spatie\Permission\Models\Permission` directamente en código de aplicación.

## Eventos de seguridad

Para agregar un nuevo evento de seguridad:

1. Agrega el caso en `App\Enums\SecurityEventType`.
2. Crea un listener, observer o flujo explícito que llame a `SecurityAuditService::record()`.
3. Registra el listener donde corresponda.
4. Escribe una prueba que verifique la fila en `security_audit_log` con el `event_type` esperado.

```php
$this->auditService->record(
    SecurityEventType::NewEvent,
    $userId,
    request()->ip(),
    ['extra' => 'context'],
);
```

Nunca escribas directamente en `security_audit_log` fuera de `SecurityAuditService`. Ese servicio es la ruta única de escritura: crea la fila y registra el evento en el canal `security` de forma consistente.

## Ver también

- [Logging y correlación](logging-correlation.md)
- [Excepciones operativas](exceptions.md)
- [ADR-005: Audit Boundary](../adr/ADR-005-audit-boundary.md)
