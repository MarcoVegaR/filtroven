<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\AuditQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditExportController extends Controller
{
    public function __construct(private readonly AuditQueryService $auditQueryService) {}

    public function __invoke(Request $request): StreamedResponse
    {
        Gate::authorize('system.audit.export');

        $rows = $this->auditQueryService->exportRows($request->query());
        $filename = 'auditoria_'.now()->format('Y-m-d_H-i-s').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Fecha', 'Fuente', 'Actor', 'Evento', 'Entidad', 'IP']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['timestamp'] ? now()->parse($row['timestamp'])->format('Y-m-d H:i:s') : '',
                    $row['source_label'] ?? '',
                    $row['actor_name'] ?? 'Sistema',
                    $row['event_label'] ?? $row['event'] ?? '',
                    $row['subject_label'] ?? '',
                    $row['ip_address'] ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
