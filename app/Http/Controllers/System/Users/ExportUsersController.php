<?php

namespace App\Http\Controllers\System\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportUsersController extends Controller
{
    /**
     * Export users as a CSV file with applied filters.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        Gate::authorize('export', User::class);

        $query = User::query()
            ->when($request->input('search'), fn ($q, string $search) => $q
                ->where(fn ($sq) => $sq
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                )
            )
            ->when($request->input('status'), fn ($q, string $status) => match ($status) {
                'active' => $q->where('is_active', true),
                'inactive' => $q->where('is_active', false),
                default => $q,
            })
            ->with('roles')
            ->orderBy('name');

        $filename = 'usuarios_'.now()->format('Y-m-d_H-i-s').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            // BOM for Excel UTF-8 compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            // Header row
            fputcsv($handle, ['Nombre', 'Correo', 'Estado', 'Roles', 'Creado']);

            $query->chunk(200, function ($users) use ($handle): void {
                foreach ($users as $user) {
                    fputcsv($handle, [
                        $user->name,
                        $user->email,
                        $user->is_active ? 'Activo' : 'Inactivo',
                        $user->roles->pluck('display_name')->map(fn ($n) => $n ?? '')->implode(', '),
                        $user->created_at?->format('Y-m-d H:i:s') ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
