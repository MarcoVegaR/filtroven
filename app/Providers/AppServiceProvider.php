<?php

namespace App\Providers;

use App\Ai\Listeners\LogUsersCopilotResponse;
use App\Listeners\RecordFailedLogin;
use App\Listeners\RecordLogout;
use App\Listeners\RecordRoleAssigned;
use App\Listeners\RecordRoleRevoked;
use App\Listeners\RecordSuccessfulLogin;
use App\Models\Role;
use App\Models\User;
use App\Observers\TwoFactorAuditObserver;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Events\AgentPrompted;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePolicies();
        $this->configureRateLimiting();
        $this->configureAuditing();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(8)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : Password::min(8)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols(),
        );
    }

    /**
     * Register model policies.
     *
     * Permission-based authorization (e.g. $user->can('system.roles.view')) is resolved
     * automatically by Spatie Permission via its register_permission_check_method gate.
     * Manual Gate::define() calls are NOT needed — Spatie handles every permission
     * that exists in the `permissions` table, making permissions fully dynamic/UI-manageable.
     */
    protected function configurePolicies(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
    }

    /**
     * Register security event listeners and model observers.
     */
    private function configureAuditing(): void
    {
        Event::listen(Login::class, RecordSuccessfulLogin::class);
        Event::listen(Failed::class, RecordFailedLogin::class);
        Event::listen(Logout::class, RecordLogout::class);
        Event::listen(RoleAttachedEvent::class, RecordRoleAssigned::class);
        Event::listen(RoleDetachedEvent::class, RecordRoleRevoked::class);
        Event::listen(AgentPrompted::class, LogUsersCopilotResponse::class);

        User::observe(TwoFactorAuditObserver::class);
    }

    /**
     * Configure application rate limiters.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('users-copilot-messages', function (Request $request) {
            $userId = $request->user()?->id;

            $key = $userId !== null
                ? 'user:'.$userId
                : Str::transliterate((string) $request->ip());

            return Limit::perMinute((int) config('ai-copilot.rate_limits.messages_per_minute', 6))
                ->by($key);
        });
    }
}
