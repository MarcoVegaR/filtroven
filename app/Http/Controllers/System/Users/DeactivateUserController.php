<?php

namespace App\Http\Controllers\System\Users;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeactivateUserController extends Controller
{
    /**
     * Deactivate the specified user, kill all their sessions, and audit the event.
     *
     * Guards: cannot deactivate self, cannot deactivate last effective admin.
     *
     * @throws ValidationException
     */
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('deactivate', $user);

        if ($user->id === $request->user()?->id) {
            throw ValidationException::withMessages([
                'user' => [__('No puedes desactivar tu propia cuenta.')],
            ]);
        }

        if (User::isLastEffectiveAdmin($user)) {
            throw ValidationException::withMessages([
                'user' => [__('No es posible desactivar al último administrador efectivo del sistema.')],
            ]);
        }

        $user->update(['is_active' => false]);

        DB::table('sessions')->where('user_id', $user->id)->delete();

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::UserDeactivated,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['target_user_id' => $user->id, 'email' => $user->email],
        );

        return back()->with('success', __('Usuario desactivado exitosamente.'));
    }
}
