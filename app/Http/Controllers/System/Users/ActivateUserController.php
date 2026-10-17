<?php

namespace App\Http\Controllers\System\Users;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActivateUserController extends Controller
{
    /**
     * Activate the specified user and audit the event.
     */
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('activate', $user);

        $user->update(['is_active' => true]);

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::UserActivated,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['target_user_id' => $user->id, 'email' => $user->email],
        );

        return back()->with('success', __('Usuario activado exitosamente.'));
    }
}
