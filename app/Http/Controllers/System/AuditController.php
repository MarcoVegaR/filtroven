<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\AuditQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function __construct(private readonly AuditQueryService $auditQueryService) {}

    public function index(Request $request): Response
    {
        Gate::authorize('system.audit.view');

        return Inertia::render('system/audit/index', [
            'events' => $this->auditQueryService->paginateIndex($request->query()),
            'filters' => $this->auditQueryService->filters($request->query()),
            'filterOptions' => $this->auditQueryService->filterOptions($request->query()),
            'hasActiveDateFilters' => $this->auditQueryService->hasActiveDateFilters($request->query()),
            'breadcrumbs' => [
                ['title' => 'Auditoría', 'href' => route('system.audit.index', absolute: false)],
            ],
        ]);
    }

    public function show(string $source, int $id): Response
    {
        Gate::authorize('system.audit.view');

        abort_unless(in_array($source, ['model', 'security'], true), 404);

        return Inertia::render('system/audit/show', [
            'event' => $this->auditQueryService->findDetail($source, $id),
            'breadcrumbs' => [
                ['title' => 'Auditoría', 'href' => route('system.audit.index', absolute: false)],
                ['title' => 'Detalle'],
            ],
        ]);
    }
}
