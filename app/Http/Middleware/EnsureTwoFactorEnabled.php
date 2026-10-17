<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorEnabled
{
    /**
     * Handle an incoming request.
     *
     * Enforces 2FA configuration for super-admin users in non-local environments.
     * If a super-admin does not have `two_factor_confirmed_at` set, they are redirected
     * to the security settings page where they can enable 2FA.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Bypass in local environment (development convenience)
        if (app()->environment('local')) {
            return $next($request);
        }

        $user = $request->user();

        // Only enforce for super-admin users who have not configured 2FA
        if ($user && $user->hasRole('super-admin') && is_null($user->two_factor_confirmed_at)) {
            return redirect()->route('security.edit')
                ->with('status', 'Debes configurar la autenticación de dos factores antes de continuar.');
        }

        return $next($request);
    }
}
