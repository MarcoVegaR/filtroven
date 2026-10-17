<?php

namespace App\Http\Controllers\System\Users;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\Users\BulkActionRequest;
use App\Models\User;
use App\Services\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class BulkDeactivateUsersController extends Controller
{
    /**
     * Execute a bulk action (deactivate, activate, delete) on multiple users.
     *
     * Per-ID validation: skips self, skips last effective admin.
     * Returns structured success/failure summary via session flash.
     */
    public function __invoke(BulkActionRequest $request): RedirectResponse
    {
        $action = $request->validated('action');
        $ids = $request->validated('ids');
        $authId = $request->user()?->id;

        $successCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            $user = User::find($id);

            if (! $user) {
                $errors[] = "ID {$id}: Usuario no encontrado.";

                continue;
            }

            // Guard: cannot act on self
            if ($user->id === $authId) {
                $errors[] = "{$user->email}: No puedes aplicar esta acción a tu propia cuenta.";

                continue;
            }

            // Guard for destructive actions: cannot remove last effective admin
            if (in_array($action, ['deactivate', 'delete']) && User::isLastEffectiveAdmin($user)) {
                $errors[] = "{$user->email}: Es el último administrador efectivo del sistema.";

                continue;
            }

            match ($action) {
                'deactivate' => $this->deactivateUser($user, $request),
                'activate' => $this->activateUser($user, $request),
                'delete' => $this->deleteUser($user, $request),
            };

            $successCount++;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $message = match ($action) {
            'deactivate' => trans_choice(
                '{1} :count usuario desactivado.|[2,*] :count usuarios desactivados.',
                $successCount,
                ['count' => $successCount],
            ),
            'activate' => trans_choice(
                '{1} :count usuario activado.|[2,*] :count usuarios activados.',
                $successCount,
                ['count' => $successCount],
            ),
            'delete' => trans_choice(
                '{1} :count usuario eliminado.|[2,*] :count usuarios eliminados.',
                $successCount,
                ['count' => $successCount],
            ),
        };

        $redirect = to_route('system.users.index')->with('success', $message);

        if (! empty($errors)) {
            $redirect->with('warning', implode(' ', $errors));
        }

        return $redirect;
    }

    /**
     * Deactivate a user and kill their sessions.
     */
    private function deactivateUser(User $user, BulkActionRequest $request): void
    {
        $user->update(['is_active' => false]);
        DB::table('sessions')->where('user_id', $user->id)->delete();

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::UserDeactivated,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['target_user_id' => $user->id, 'email' => $user->email, 'bulk' => true],
        );
    }

    /**
     * Activate a user.
     */
    private function activateUser(User $user, BulkActionRequest $request): void
    {
        $user->update(['is_active' => true]);

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::UserActivated,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['target_user_id' => $user->id, 'email' => $user->email, 'bulk' => true],
        );
    }

    /**
     * Delete a user.
     */
    private function deleteUser(User $user, BulkActionRequest $request): void
    {
        $user->delete();

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::UserDeleted,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['target_user_id' => $user->id, 'email' => $user->email, 'bulk' => true],
        );
    }
}
