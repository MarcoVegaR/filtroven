# Colas y scheduler

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores que agregan jobs, workers o tareas programadas
    - Fuente verificable: `.env.example`, `routes/console.php`, `composer.json`, `docs/adr/ADR-008-queue-and-scheduler-policy.md`

Esta guía define cuándo usar colas, qué política de retry debe tener cada job y cómo tratar tareas programadas.

## Criterios para enviar a cola

Antes de despachar una operación como job, confirma:

- La operación toma más de 200 ms o debe reintentarse si falla.
- La operación puede diferirse sin bloquear la respuesta HTTP.
- El job implementa `ShouldQueue`.
- El job no depende de estado mutable que pueda cambiar entre el despacho y la ejecución.
- Si se despacha desde HTTP, el `correlation_id` se propaga automáticamente.
- Si se despacha desde consola o scheduler y necesita trazabilidad, el job agrega `correlation_id` con `Context::add()`.

No uses cola para validaciones simples, lecturas síncronas o cálculos que deben devolver una respuesta inmediata.

## Política obligatoria de retry

Todo job que implemente `ShouldQueue` debe declarar explícitamente:

```php
public int $tries = 3;
public int|array $backoff = [10, 30, 60];
public int $timeout = 120;
```

No crees jobs sin `$tries`, `$backoff` y `$timeout`.

## Redis y workers locales

- La conexión base es `QUEUE_CONNECTION=redis`.
- Redis también se usa para sesiones y cache en `.env.example`.
- `composer run local:queue` está disponible como worker local independiente.

## Scheduler

Las tareas programadas verificables viven en `routes/console.php`. Las tareas que no sean idempotentes bajo ejecución concurrente deben usar `withoutOverlapping()`.

La política vigente registrada en ADR-008 incluye:

| Comando | Frecuencia | Propósito |
| --- | --- | --- |
| `queue:prune-failed --hours=168` | Diaria | Eliminar jobs fallidos con más de 7 días |
| `model:prune` | Diaria | Podar modelos Eloquent `Prunable` |

## Ver también

- [Logging y correlación](logging-correlation.md)
- [Servicios locales](local-services.md)
- [ADR-008: Queue and Scheduler Policy](../adr/ADR-008-queue-and-scheduler-policy.md)
