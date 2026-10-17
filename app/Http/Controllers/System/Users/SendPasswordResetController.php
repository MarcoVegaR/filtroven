<?php

namespace App\Http\Controllers\System\Users;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;

class SendPasswordResetController extends Controller
{
    /**
     * Send a password reset notification to the specified user.
     */
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('sendReset', $user);

        Password::broker()->sendResetLink(['email' => $user->email]);

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::PasswordResetSent,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['target_user_id' => $user->id, 'email' => $user->email],
        );

        return back()->with('success', __('Correo de restablecimiento de contraseña enviado exitosamente.'));
    }
}
