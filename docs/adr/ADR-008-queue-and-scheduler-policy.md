# ADR-008: Queue and Scheduler Policy

**Status**: Vigente  
**Date**: 2026-03-21  
**Authors**: Caracoders Engineering

**Originating PRD**: PRD-03, reconciled by PRD-07  
**Implementation references**: `routes/console.php`, `composer.json`, `phpunit.xml`, `bootstrap/app.php`

---

## Context

The boilerplate needs consistent conventions for queued jobs and scheduled tasks to ensure observability, reliability, and safe concurrent execution across all future modules.

---

## Decision

### Queue criteria

A task MUST be dispatched as a queued job when ANY of the following apply:

- The operation takes longer than ~200ms (e.g., sending email, calling external APIs, processing files).
- The operation must be retried on failure (payment processing, webhook delivery).
- The operation can be deferred without affecting the HTTP response (audit exports, report generation).
- The operation should not block the user's response cycle.

A task MUST NOT be queued when:

- It is a simple synchronous validation or database read.
- It requires an immediate response value (synchronous returns).
- It is an in-memory-only computation.

### Mandatory job properties

Every job class that implements `ShouldQueue` MUST define the following class properties:

```php
public int $tries = 3;          // Maximum number of attempts
public int|array $backoff = [10, 30, 60]; // Delay (seconds) between retries
public int $timeout = 120;       // Maximum execution time per attempt
```

Jobs MUST NOT be created without explicit retry policies. An architecture test enforces this convention.

### Context propagation

All queued jobs dispatched from an HTTP request inherit the `correlation_id` from the originating request automatically via the `Context` facade. No extra code is required in the job class — Laravel's queue worker restores the Context from the job payload.

Jobs dispatched from the scheduler or artisan commands (outside HTTP context) will not have a pre-existing `correlation_id`. If a job needs to log with a correlation ID in these cases, it should call:

```php
Context::add('correlation_id', (string) Str::uuid());
```

at the start of its `handle()` method.

### `failed_jobs` table

Failed jobs are recorded in the `failed_jobs` table (Laravel default). Teams MUST NOT suppress or ignore failed jobs. The `failed_jobs` table is the source of truth for failed async operations.

Failed jobs older than 7 days are pruned by the scheduled `queue:prune-failed` task.

### `withoutOverlapping()`

All scheduled tasks that are NOT idempotent under concurrent execution MUST use `withoutOverlapping()`. This prevents double-execution when a long-running task overlaps its next scheduled slot (e.g., slow `model:prune` running longer than 24 hours).

### Registered scheduled tasks

The following tasks are registered in `routes/console.php`:

| Command                          | Schedule | `withoutOverlapping` | Purpose                              |
| -------------------------------- | -------- | -------------------- | ------------------------------------ |
| `queue:prune-failed --hours=168` | Daily    | ✅ Yes               | Remove failed jobs older than 7 days |
| `model:prune`                    | Daily    | ✅ Yes               | Prune Eloquent Prunable models       |

### Queue connection baseline

- **Default**: `QUEUE_CONNECTION=redis` (`.env.example`).
- Redis is required for local development (`composer run dev` starts a queue worker).
- The `jobs`, `job_batches`, and `failed_jobs` tables must exist after running migrations.
- `composer run local:queue` is available as a standalone queue worker command for local development.

---

## Rationale

### Why explicit retry policies?

Silent failures in async systems are hard to detect. Explicit `$tries` and `$backoff` force developers to reason about failure modes when writing the job. The architecture test catches jobs that bypass this requirement.

### Why `withoutOverlapping()` on scheduled tasks?

Scheduler tasks run on cron schedules without awareness of whether the previous run has completed. Long-running operations like `model:prune` or audit pruning could overlap on a slow database, causing data corruption or double-processing.

### Why `Context` instead of job constructor injection for correlation?

Job constructor injection would require every job to accept and pass a `correlationId` parameter, coupling all jobs to the correlation system. The `Context` facade handles propagation transparently via Laravel's serializable context.

---

## Consequences

- ✅ All jobs have documented retry behavior — no silent infinite failures.
- ✅ Correlation IDs propagate to queue workers without manual effort.
- ✅ Failed job history is preserved and visible in `failed_jobs` table.
- ✅ Scheduled tasks cannot overlap and cause concurrency bugs.
- ⚠️ Jobs dispatched from console/scheduler context must explicitly set `correlation_id` if log traceability is needed.
- ⚠️ Missing `$tries`/`$backoff`/`$timeout` on a job will be caught by the architecture test — this is intentional.
